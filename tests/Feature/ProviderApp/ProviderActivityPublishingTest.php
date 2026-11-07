<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Activity;
use App\Notifications\Activities\ActivityApprovedNotification;
use App\Services\ActivityModerationService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * Automatic publishing (config/activities.php, ACTIVITY_AUTO_PUBLISH). An admin must approve every activity; the setting decides whether approval
 * also PUBLISHES it (on, the default and the behaviour until now) or leaves it approved and hidden for the PROVIDER to publish when ready.
 * PATCH /provider/activities/{id}/status is how a provider publishes, pauses and resumes.
 */
class ProviderActivityPublishingTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private Activity $trip;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->approvedProvider();
        [$this->trip] = $this->makeBookableActivity($this->a['provider'], 'pending_review', ['title' => 'Sunset Cruise']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function approve(): Activity
    {
        return app(ActivityModerationService::class)->approve($this->trip->refresh());
    }

    private function setStatus(string $status, ?array $as = null, $id = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'PATCH', '/api/v1/provider/activities/'.($id ?? $this->trip->id).'/status', ['status' => $status]);
    }

    private function inCatalogue(string $title): bool
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return str_contains($this->getJson('/api/v1/public/activities?per_page=100')->assertOk()->getContent(), $title);
    }

    // --- The setting ---------------------------------------------------------------------------------------------------

    public function test_automatic_publishing_is_on_by_default_so_nothing_changes_until_it_is_turned_off(): void
    {
        $this->assertTrue(config('activities.auto_publish'));

        $approved = $this->approve();

        $this->assertSame('published', $approved->status);
        $this->assertNotNull($approved->published_at);
        $this->assertTrue($this->inCatalogue('Sunset Cruise'));
    }

    public function test_with_it_off_approval_leaves_the_activity_approved_and_hidden(): void
    {
        config(['activities.auto_publish' => false]);

        $approved = $this->approve();

        $this->assertSame('approved', $approved->status);
        $this->assertNull($approved->published_at, 'It has not been published, so it has no publication date');
        $this->assertFalse($this->inCatalogue('Sunset Cruise'));
        $this->getJson('/api/v1/public/activities/'.$approved->slug)->assertNotFound();
    }

    public function test_an_approved_activity_cannot_be_booked_until_it_is_published(): void
    {
        config(['activities.auto_publish' => false]);
        $this->approve();
        $option = $this->trip->bookingOptions()->first();
        [$user] = $this->makeCustomer();

        $this->hit($this->tokenFor($user, 'customer'), 'POST', '/api/v1/customer/reservations', [
            'activity' => $this->trip->uuid, 'booking_option' => $option->uuid, 'reservation_date' => '2026-11-21', 'participants' => ['adult' => 1],
        ])->assertNotFound();
    }

    public function test_admin_approval_is_still_required_whatever_the_setting(): void
    {
        foreach ([true, false] as $setting) {
            config(['activities.auto_publish' => $setting]);
            [$draft] = $this->makeBookableActivity($this->a['provider'], 'draft');
            [$pending] = $this->makeBookableActivity($this->a['provider'], 'pending_review');
            [$rejected] = $this->makeBookableActivity($this->a['provider'], 'rejected');

            foreach ([$draft, $pending, $rejected] as $activity) {
                $this->setStatus('active', null, $activity->id)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
                $this->assertNotSame('published', $activity->refresh()->status, 'Not even with the setting '.var_export($setting, true));
            }
        }
    }

    public function test_the_approval_message_says_whether_the_activity_is_live(): void
    {
        $this->approve();
        $this->assertSame(1, $this->a['user']->notifications()->where('type', ActivityApprovedNotification::class)->count());
        $this->assertStringContainsString('is now live', $this->a['user']->notifications()->where('type', ActivityApprovedNotification::class)->first()->data['message']);

        config(['activities.auto_publish' => false]);
        [$waiting] = $this->makeBookableActivity($this->a['provider'], 'pending_review', ['title' => 'Night Dive']);
        app(ActivityModerationService::class)->approve($waiting);

        $message = $this->a['user']->notifications()->where('type', ActivityApprovedNotification::class)->get()->pluck('data.message')->first(fn ($m) => str_contains($m, 'Night Dive'));
        $this->assertStringContainsString('Publish it when you are ready', $message);
        $this->assertStringNotContainsString('now live', $message);
    }

    public function test_the_admin_is_told_on_screen_what_the_approval_did(): void
    {
        $admin = $this->makeAdmin();
        $this->flushHeaders();

        $this->actingAs($admin, 'web')->post(route('admin.activities.approve', $this->trip))->assertSessionHas('status', __('admin.activities.approved'));

        config(['activities.auto_publish' => false]);
        [$waiting] = $this->makeBookableActivity($this->a['provider'], 'pending_review');
        $this->actingAs($admin, 'web')->post(route('admin.activities.approve', $waiting))->assertSessionHas('status', __('admin.activities.approved_waiting'));

        $this->actingAs($admin, 'web')->get(route('admin.activities.index', ['status' => 'approved']))->assertOk()->assertSee(__('admin.activities.statuses.approved'));
    }

    // --- The provider publishes, pauses, resumes -----------------------------------------------------------------------------

    public function test_the_provider_publishes_an_approved_activity(): void
    {
        config(['activities.auto_publish' => false]);
        $this->approve();

        $response = $this->setStatus('active')->assertOk();

        $this->assertSame(['status' => true, 'message' => 'Activity status updated', 'data' => ['id' => $this->trip->id, 'status' => 'active']], $response->json());
        $this->assertSame('published', $this->trip->refresh()->status);
        $this->assertNotNull($this->trip->published_at);
        $this->assertTrue($this->inCatalogue('Sunset Cruise'));
    }

    public function test_pausing_hides_the_activity_but_leaves_confirmed_bookings_alone_and_it_can_be_resumed(): void
    {
        $this->approve();
        [, $customer] = $this->makeCustomer();
        $booking = $this->bookActivity($customer, $this->trip, $this->trip->bookingOptions()->first(), \Illuminate\Support\Carbon::parse('2026-11-21'));
        $this->payFor($booking);

        $this->setStatus('paused')->assertOk()->assertJsonPath('data.status', 'paused');
        $this->assertSame('inactive', $this->trip->refresh()->status);
        $this->assertFalse($this->inCatalogue('Sunset Cruise'));
        $this->assertSame('confirmed', $booking->refresh()->status->value, 'Pausing does not cancel what was already booked');

        $this->setStatus('active')->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertTrue($this->inCatalogue('Sunset Cruise'));
    }

    public function test_inactive_is_accepted_as_another_name_for_paused(): void
    {
        $this->approve();

        $this->setStatus('inactive')->assertOk()->assertJsonPath('data.status', 'paused');
    }

    public function test_a_transition_that_makes_no_sense_is_a_conflict(): void
    {
        config(['activities.auto_publish' => false]);
        $this->approve();

        $this->setStatus('paused')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');   // approved, never published: nothing to pause
        $this->setStatus('active')->assertOk();
        $this->setStatus('active')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');   // already active
    }

    public function test_only_your_own_activity_can_be_changed_and_another_providers_is_not_found(): void
    {
        $this->approve();
        $other = $this->approvedProvider();

        $theirs = $this->setStatus('paused', $other)->assertNotFound();
        $missing = $this->setStatus('paused', $other, 999999)->assertNotFound();
        $text = $this->setStatus('paused', $other, 'abc')->assertNotFound();

        foreach ([$theirs, $missing, $text] as $response) {
            $this->assertSame('RESOURCE_NOT_FOUND', $response->json('code'));
        }
        $this->assertSame($theirs->json('message'), $missing->json('message'));
        $this->assertSame('published', $this->trip->refresh()->status, 'Untouched');
    }

    public function test_the_status_must_be_one_of_the_known_ones_and_only_an_approved_provider_may_ask(): void
    {
        foreach (['', 'draft', 'published', 'deleted', 'pending_review'] as $bad) {
            $this->setStatus($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }

        $this->setStatus('paused', $this->unapprovedProvider())->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'PATCH', "/api/v1/provider/activities/{$this->trip->id}/status", ['status' => 'paused'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'PATCH', "/api/v1/provider/activities/{$this->trip->id}/status", ['status' => 'paused'])->assertUnauthorized();
    }

    // --- Approval is not bypassed by editing ------------------------------------------------------------------------------------

    public function test_changing_an_approved_activity_before_it_is_published_sends_it_back_to_review(): void
    {
        config(['activities.auto_publish' => false]);
        $this->approve();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->a['token'])->putJson("/api/v1/provider/activities/{$this->trip->uuid}", ['title' => 'Something the admin never saw'])->assertOk();

        $this->assertSame('pending_review', $this->trip->refresh()->status, 'Otherwise a provider could change an activity after approval and publish it unreviewed');
        $this->setStatus('active')->assertStatus(409);
    }
}
