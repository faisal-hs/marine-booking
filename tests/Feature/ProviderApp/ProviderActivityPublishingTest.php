<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Activity;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ActivityPublishingService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * Publishing, under the final rules. The admin approves the PROVIDER account only; an approved provider creates, edits and publishes their own
 * activities, and ACTIVITY_AUTO_PUBLISH (config/activities.php) decides whether a publish REQUEST makes the activity live at once or keeps it hidden,
 * "ready", until the provider activates it. An admin can suspend any activity; that is kept apart from the provider's own pausing and a provider can
 * never switch it off.
 */
class ProviderActivityPublishingTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private Activity $trip;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->approvedProvider();
        [$this->trip] = $this->makeBookableActivity($this->a['provider'], 'draft', ['title' => 'Sunset Cruise']);
        $this->admin = $this->makeAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function publish(string $route = 'publish', ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'POST', "/api/v1/provider/activities/{$this->trip->uuid}/{$route}");
    }

    private function setStatus(string $status, ?array $as = null, $id = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'PATCH', '/api/v1/provider/activities/'.($id ?? $this->trip->id).'/status', ['status' => $status]);
    }

    private function stop(string $reason = 'A complaint was upheld'): Activity
    {
        return app(ActivityPublishingService::class)->suspend($this->trip->refresh(), $reason, $this->admin);
    }

    private function inCatalogue(string $title): bool
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return str_contains($this->getJson('/api/v1/public/activities?per_page=100')->assertOk()->getContent(), $title);
    }

    // --- The publish request and the setting ---------------------------------------------------------------------------

    public function test_a_publish_request_makes_the_activity_live_at_once_by_default(): void
    {
        $this->assertTrue(config('activities.auto_publish'));

        $response = $this->publish()->assertOk();

        $this->assertSame(['published', 'Activity published.'], [$response->json('data.status'), $response->json('message')]);
        $this->assertNotNull($this->trip->refresh()->published_at);
        $this->assertTrue($this->inCatalogue('Sunset Cruise'));
    }

    public function test_with_automatic_publishing_off_a_publish_request_keeps_it_ready_and_hidden(): void
    {
        config(['activities.auto_publish' => false]);

        $response = $this->publish()->assertOk();

        $this->assertSame('approved', $response->json('data.status'));
        $this->assertStringContainsString('ready', $response->json('message'));
        $this->assertNull($this->trip->refresh()->published_at, 'It has not been published, so it has no publication date');
        $this->assertFalse($this->inCatalogue('Sunset Cruise'));
        $this->getJson('/api/v1/public/activities/'.$this->trip->slug)->assertNotFound();
    }

    public function test_the_old_submit_for_review_route_is_the_same_request(): void
    {
        $this->publish('submit-for-review')->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_a_ready_activity_cannot_be_booked_until_its_provider_activates_it(): void
    {
        config(['activities.auto_publish' => false]);
        $this->publish()->assertOk();
        $option = $this->trip->bookingOptions()->first();
        [$user] = $this->makeCustomer();
        $book = fn () => $this->hit($this->tokenFor($user, 'customer'), 'POST', '/api/v1/customer/reservations', [
            'activity' => $this->trip->uuid, 'booking_option' => $option->uuid, 'reservation_date' => '2026-11-21', 'participants' => ['adult' => 1],
        ]);

        $book()->assertNotFound();

        $this->setStatus('active')->assertOk();
        $this->assertContains($book()->getStatusCode(), [200, 201], 'Once it is live it can be booked');
    }

    public function test_activating_always_goes_live_whatever_the_setting(): void
    {
        foreach ([true, false] as $setting) {
            config(['activities.auto_publish' => $setting]);

            foreach (['draft', 'approved', 'inactive'] as $from) {
                [$activity] = $this->makeBookableActivity($this->a['provider'], $from);

                $this->setStatus('active', null, $activity->id)->assertOk()->assertJsonPath('data.status', 'active');

                $this->assertSame('published', $activity->refresh()->status, "From {$from}, with the setting ".var_export($setting, true));
            }
        }
    }

    public function test_only_a_draft_can_be_asked_to_publish(): void
    {
        foreach (['approved', 'published', 'inactive'] as $status) {
            $this->trip->update(['status' => $status]);

            $this->publish()->assertStatus(422)->assertJsonValidationErrors('status');
            $this->assertSame($status, $this->trip->refresh()->status);
        }
    }

    // --- Pausing, resuming, and nonsense -----------------------------------------------------------------------------

    public function test_pausing_hides_the_activity_but_leaves_confirmed_bookings_alone_and_it_can_be_resumed(): void
    {
        $id = $this->makeBooking($this->a, $this->customerNamed('Fatima'), '2026-11-20', 'confirmed', 500);
        $live = $this->a['activity'];

        $this->setStatus('paused', null, $live->id)->assertOk()->assertJsonPath('data.status', 'paused');

        $this->assertSame('inactive', $live->refresh()->status);
        $this->assertFalse($live->isSuspended(), 'A provider\'s own pause is not a suspension');
        $this->assertSame('confirmed', Reservation::find($id)->status->value);
        $this->assertFalse($this->inCatalogue($live->title));

        $this->setStatus('active', null, $live->id)->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertTrue($this->inCatalogue($live->title));
    }

    public function test_inactive_is_accepted_as_another_name_for_paused(): void
    {
        $this->setStatus('inactive', null, $this->a['activity']->id)->assertOk()->assertJsonPath('data.status', 'paused');
    }

    public function test_a_transition_that_makes_no_sense_is_a_conflict(): void
    {
        $this->setStatus('paused')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');   // a draft cannot be paused
        $this->setStatus('active', null, $this->a['activity']->id)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');   // already live
        $this->setStatus('bogus')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_only_your_own_activity_can_be_changed_and_another_providers_is_not_found(): void
    {
        $other = $this->approvedProvider();

        $this->setStatus('active', $other)->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->publish('publish', $other)->assertStatus(403);

        $this->assertSame('draft', $this->trip->refresh()->status);
    }

    // --- Editing never needs anyone's permission -------------------------------------------------------------------------

    public function test_editing_a_live_or_ready_activity_applies_at_once_and_keeps_its_status(): void
    {
        foreach (['published', 'approved'] as $status) {
            $this->trip->update(['status' => $status]);

            $this->hit($this->a['token'], 'PUT', "/api/v1/provider/activities/{$this->trip->uuid}", ['title' => "Renamed while {$status}"])->assertOk();

            $this->assertSame([$status, "Renamed while {$status}"], [$this->trip->refresh()->status, $this->trip->title]);
        }
    }

    // --- An admin's suspension: the provider can never switch it off ---------------------------------------------------------

    public function test_a_provider_can_never_reactivate_a_suspended_activity_whatever_route_or_setting(): void
    {
        foreach ([true, false] as $setting) {
            config(['activities.auto_publish' => $setting]);
            $this->trip->update(['status' => 'published', 'suspended_at' => null, 'suspension_reason' => null, 'suspended_from_status' => null]);
            $this->stop('A complaint was upheld');

            $this->setStatus('active')->assertForbidden()->assertJsonPath('code', 'ACTIVITY_SUSPENDED_BY_ADMIN');
            $this->setStatus('paused')->assertForbidden()->assertJsonPath('code', 'ACTIVITY_SUSPENDED_BY_ADMIN');
            $this->publish()->assertForbidden();
            $this->publish('submit-for-review')->assertForbidden();

            $this->trip->refresh();
            $this->assertSame('inactive', $this->trip->status, 'Setting '.var_export($setting, true));
            $this->assertTrue($this->trip->isSuspended());
            $this->assertFalse($this->inCatalogue('Sunset Cruise'));
        }
    }

    public function test_a_suspension_is_refused_from_every_state_it_can_be_in(): void
    {
        foreach (['published', 'approved', 'inactive'] as $before) {
            $this->trip->update(['status' => $before, 'suspended_at' => null, 'suspension_reason' => null, 'suspended_from_status' => null]);
            $this->stop();

            $this->setStatus('active')->assertForbidden()->assertJsonPath('code', 'ACTIVITY_SUSPENDED_BY_ADMIN');
            $this->assertTrue($this->trip->refresh()->isSuspended(), "Suspended while {$before}");
        }
    }

    public function test_the_provider_cannot_clear_a_suspension_by_editing(): void
    {
        $this->trip->update(['status' => 'published']);
        $this->stop('A complaint was upheld');

        $this->hit($this->a['token'], 'PUT', "/api/v1/provider/activities/{$this->trip->uuid}", [
            'title' => 'A better title', 'status' => 'published', 'suspended_at' => null, 'suspension_reason' => null, 'suspended_from_status' => null, 'suspended_by_user_id' => null,
        ])->assertOk();

        $this->trip->refresh();
        $this->assertSame(['A better title', 'inactive', true, 'A complaint was upheld'], [$this->trip->title, $this->trip->status, $this->trip->isSuspended(), $this->trip->suspension_reason]);
    }

    public function test_the_provider_can_see_that_an_admin_stopped_it_and_why(): void
    {
        $this->trip->update(['status' => 'published']);
        $this->stop('A complaint was upheld');

        $data = $this->hit($this->a['token'], 'GET', "/api/v1/provider/activities/{$this->trip->uuid}")->assertOk()->json('data');

        $this->assertTrue($data['suspended']);
        $this->assertSame('A complaint was upheld', $data['suspension_reason']);
    }

    public function test_an_activity_that_is_not_suspended_says_so(): void
    {
        $this->trip->update(['status' => 'published']);

        $data = $this->hit($this->a['token'], 'GET', "/api/v1/provider/activities/{$this->trip->uuid}")->assertOk()->json('data');

        $this->assertFalse($data['suspended']);
        $this->assertArrayNotHasKey('suspension_reason', $data);
    }

    public function test_only_an_admin_lifting_the_suspension_gives_the_provider_control_back(): void
    {
        $this->trip->update(['status' => 'published']);
        $this->stop();
        $this->setStatus('active')->assertForbidden();

        app(ActivityPublishingService::class)->lift($this->trip->refresh());

        $this->assertSame('published', $this->trip->refresh()->status, 'Back as it was');
        $this->setStatus('paused')->assertOk();
        $this->setStatus('active')->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_a_suspended_paused_activity_comes_back_paused_and_the_provider_decides(): void
    {
        $this->trip->update(['status' => 'inactive']);
        $this->stop();

        app(ActivityPublishingService::class)->lift($this->trip->refresh());

        $this->assertSame('inactive', $this->trip->refresh()->status, 'The provider had paused it: lifting does not switch it on');
        $this->assertFalse($this->inCatalogue('Sunset Cruise'));
        $this->setStatus('active')->assertOk();
    }

    // --- What a provider is not told -------------------------------------------------------------------------------------

    public function test_a_provider_is_not_notified_of_their_own_publishing_pausing_or_activating(): void
    {
        Notification::fake();

        $this->publish()->assertOk();
        $this->setStatus('paused')->assertOk();
        $this->setStatus('active')->assertOk();

        Notification::assertNothingSent();
    }
}
