<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function makePublishedActivity(): Activity
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);

        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00', 'price' => 300]);

        return $activity;
    }

    public function test_single_date_query_returns_flat_period_list(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?date=2026-09-05");

        $response->assertOk()
            ->assertJsonPath('data.date', '2026-09-05')
            ->assertJsonCount(1, 'data.periods');
    }

    public function test_no_parameters_defaults_to_today(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability");

        $response->assertOk()->assertJsonPath('data.date', now()->toDateString());
    }

    public function test_date_range_query_returns_keyed_object(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?from_date=2026-09-01&to_date=2026-09-07");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['2026-09-01', '2026-09-05']]);
    }

    public function test_month_query_returns_every_day_of_that_month(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?month=2026-09");

        $response->assertOk()->assertJsonCount(30, 'data');
    }

    public function test_range_larger_than_90_days_is_rejected(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?from_date=2026-01-01&to_date=2026-12-31");

        $response->assertStatus(422);
    }

    public function test_to_date_before_from_date_is_rejected(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?from_date=2026-09-20&to_date=2026-09-01");

        $response->assertStatus(422);
    }

    public function test_invalid_date_format_is_rejected(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?date=09-05-2026");

        $response->assertStatus(422);
    }

    public function test_draft_activity_returns_404_for_availability(): void
    {
        $activity = $this->makePublishedActivity();
        $activity->update(['status' => 'draft']);

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?date=2026-09-05");

        $response->assertStatus(404);
    }

    public function test_inactive_period_never_appears_in_public_availability(): void
    {
        $activity = $this->makePublishedActivity();
        $activity->schedulePeriods()->first()->update(['status' => 'inactive']);

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?date=2026-09-05");

        $response->assertOk()->assertJsonCount(0, 'data.periods');
    }

    public function test_blackout_override_excludes_date_from_public_availability(): void
    {
        $activity = $this->makePublishedActivity();
        $activity->availabilityOverrides()->create(['date_from' => '2026-09-05', 'date_to' => '2026-09-05', 'is_unavailable' => true]);

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?date=2026-09-05");

        $response->assertOk()->assertJsonCount(0, 'data.periods');
    }

    public function test_endpoint_requires_no_authentication(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?date=2026-09-05");

        $response->assertOk();
    }
}
