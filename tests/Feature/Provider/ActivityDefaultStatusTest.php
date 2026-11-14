<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * A new activity is ACTIVE (live) by default: when the platform publishes automatically (ACTIVITY_AUTO_PUBLISH) and the provider may publish. If the provider
 * cannot go live yet it is saved as a draft, and the answer says why (creating never fails for that). `status: draft` asks for a draft.
 */
class ActivityDefaultStatusTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->a = $this->approvedProvider();
        $this->category = $this->a['activity']->category_id;
    }

    private function create(array $data = [], ?string $token = null)
    {
        return $this->hit($token ?? $this->a['token'], 'POST', '/api/v1/provider/activities', array_merge(['category_id' => $this->category, 'title' => 'Paradise Island Day Trip'], $data));
    }

    private function publicShows(string $slug): int
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/v1/public/activities/{$slug}")->status();
    }

    // --- Active by default -------------------------------------------------------------------------------------------

    public function test_a_new_activity_is_active_and_live_at_once(): void
    {
        $r = $this->create()->assertCreated();

        $this->assertSame('published', $r->json('data.status'));
        $this->assertSame('Activity created successfully.', $r->json('message'), 'Nothing is wrong, so nothing more is said');
        $activity = Activity::where('uuid', $r->json('data.uuid'))->firstOrFail();
        $this->assertNotNull($activity->published_at);
        $this->assertSame(200, $this->publicShows($activity->slug), 'Customers can see it');
    }

    public function test_leaving_the_status_out_null_and_active_all_mean_active(): void
    {
        foreach ([[], ['status' => null], ['status' => 'active']] as $i => $extra) {
            $this->assertSame('published', $this->create(['title' => "Trip {$i}"] + $extra)->assertCreated()->json('data.status'));
        }
    }

    public function test_a_draft_can_still_be_asked_for_and_is_not_live(): void
    {
        $r = $this->create(['status' => 'draft'])->assertCreated();

        $this->assertSame('draft', $r->json('data.status'));
        $this->assertSame('Activity created successfully.', $r->json('message'));
        $activity = Activity::where('uuid', $r->json('data.uuid'))->firstOrFail();
        $this->assertNull($activity->published_at);
        $this->assertSame(404, $this->publicShows($activity->slug), 'A draft is not shown to customers');
    }

    public function test_only_active_or_draft_can_be_asked_for(): void
    {
        foreach (['published', 'inactive', 'approved', 'suspended', 'live', 'ACTIVE'] as $bad) {
            $this->create(['status' => $bad])->assertStatus(422)->assertJsonValidationErrors('status');
        }

        $this->assertSame(1, Activity::count(), 'Only the setup activity: a refused request creates nothing');
    }

    public function test_everything_in_one_request_comes_out_active_with_its_photos_and_prices(): void
    {
        $png = fn ($n) => UploadedFile::fake()->createWithContent($n, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->app['auth']->forgetGuards();

        $r = $this->withHeaders(['Authorization' => "Bearer {$this->a['token']}", 'Accept' => 'application/json'])->post('/api/v1/provider/activities', [
            'category_id' => $this->category, 'title' => 'All at once', 'images' => [$png('a.png'), $png('b.png')],
            'booking_options' => [['name' => 'Standard', 'booking_model' => 'per_person', 'is_default' => true, 'pricing_rules' => [['participant_type' => 'adult', 'price' => 450]]]],
        ])->assertCreated();

        $this->assertSame(['published', 2, 450], [$r->json('data.status'), count($r->json('data.images')), $r->json('data.price')]);
    }

    public function test_it_is_bookable_in_the_customer_app_as_soon_as_it_is_priced(): void
    {
        $r = $this->create(['title' => 'Bookable at once', 'booking_options' => [['name' => 'Standard', 'booking_model' => 'per_person', 'is_default' => true, 'pricing_rules' => [['participant_type' => 'adult', 'price' => 450]]]],
            'schedule' => [['day_of_week' => 'saturday', 'start_time' => '09:00', 'end_time' => '17:00']]])->assertCreated();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $titles = array_column($this->getJson('/api/v1/customer-app/activities?q=Bookable')->assertOk()->json('data'), 'title');

        $this->assertSame(['Bookable at once'], $titles);
        $this->assertSame('published', $r->json('data.status'));
    }

    public function test_the_provider_list_counts_it_as_active(): void
    {
        $before = $this->hit($this->a['token'], 'GET', '/api/v1/provider/activities')->json('counts');

        $this->create()->assertCreated();
        $this->create(['title' => 'A draft', 'status' => 'draft'])->assertCreated();

        $after = $this->hit($this->a['token'], 'GET', '/api/v1/provider/activities')->json('counts');
        $this->assertSame([$before['active'] + 1, $before['draft'] + 1, $before['all'] + 2], [$after['active'], $after['draft'], $after['all']]);
    }

    // --- When it cannot go live ----------------------------------------------------------------------------------------

    public function test_a_provider_who_is_not_verified_cannot_create_activities_at_all(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            [, , $token] = $this->makeProvider($status);

            $this->create(['title' => "From a {$status} provider"], $token)->assertForbidden();
        }

        $this->assertSame(1, Activity::count(), 'Nothing was created: only the setup activity');
    }

    public function test_a_suspended_provider_account_gets_a_draft(): void
    {
        $a = $this->approvedProvider();
        $a['user']->forceFill(['status' => 'suspended'])->save();

        $r = $this->create([], $a['token']);

        // A suspended account may be refused outright before it gets here; if it can create at all, it must not go live.
        if ($r->status() === 201) {
            $this->assertSame('draft', $r->json('data.status'));
            $this->assertStringContainsString('suspended', $r->json('message'));
        } else {
            $this->assertContains($r->status(), [401, 403]);
        }
    }

    public function test_when_the_platform_does_not_publish_automatically_it_stays_a_draft(): void
    {
        config(['activities.auto_publish' => false]);

        $r = $this->create()->assertCreated();

        $this->assertSame('draft', $r->json('data.status'));
        $this->assertStringContainsString('not published automatically', $r->json('message'));
    }

    public function test_a_draft_that_was_held_back_can_be_published_later(): void
    {
        config(['activities.auto_publish' => false]);
        $uuid = $this->create()->assertCreated()->json('data.uuid');
        $this->assertSame('draft', Activity::where('uuid', $uuid)->value('status'));

        config(['activities.auto_publish' => true]);
        $this->hit($this->a['token'], 'POST', "/api/v1/provider/activities/{$uuid}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    }

    // --- Publishing what is already active -------------------------------------------------------------------------------

    public function test_the_old_create_then_publish_flow_still_works(): void
    {
        $uuid = $this->create()->json('data.uuid');
        $publishedAt = Activity::where('uuid', $uuid)->value('published_at');

        $publish = $this->hit($this->a['token'], 'POST', "/api/v1/provider/activities/{$uuid}/publish")->assertOk();
        $alias = $this->hit($this->a['token'], 'POST', "/api/v1/provider/activities/{$uuid}/submit-for-review")->assertOk();

        $this->assertSame(['published', 'published', 'Activity published.'], [$publish->json('data.status'), $alias->json('data.status'), $publish->json('message')]);
        $this->assertEquals($publishedAt, Activity::where('uuid', $uuid)->value('published_at'), 'Asking again changes nothing');
    }

    public function test_a_paused_or_ready_activity_is_still_not_a_draft_to_publish(): void
    {
        foreach (['inactive', 'approved'] as $status) {
            $uuid = $this->create(['title' => "State {$status}"])->json('data.uuid');
            Activity::where('uuid', $uuid)->update(['status' => $status]);

            $this->hit($this->a['token'], 'POST', "/api/v1/provider/activities/{$uuid}/publish")->assertStatus(422)->assertJsonValidationErrors('status');
        }
    }

    public function test_an_activity_an_admin_stopped_cannot_be_published_again_by_asking(): void
    {
        $uuid = $this->create()->json('data.uuid');
        Activity::where('uuid', $uuid)->update(['status' => 'inactive', 'suspended_at' => now(), 'suspension_reason' => 'Complaint', 'suspended_from_status' => 'published']);

        $this->hit($this->a['token'], 'POST', "/api/v1/provider/activities/{$uuid}/publish")->assertForbidden();

        $this->assertSame('inactive', Activity::where('uuid', $uuid)->value('status'));
    }

    // --- Who ----------------------------------------------------------------------------------------------------------------

    public function test_a_customer_and_nobody_cannot_create_activities(): void
    {
        [$customer] = $this->makeCustomer();

        $this->hit($this->tokenFor($customer, 'customer'), 'POST', '/api/v1/provider/activities', ['category_id' => $this->category, 'title' => 'Nope'])->assertForbidden();
        $this->hit(null, 'POST', '/api/v1/provider/activities', ['category_id' => $this->category, 'title' => 'Nope'])->assertUnauthorized();
        $this->assertSame(0, Activity::where('title', 'Nope')->count());
    }
}
