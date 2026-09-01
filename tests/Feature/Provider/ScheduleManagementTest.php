<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProviderActivity(): array
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
        ]);

        return [$user, $provider, $token, $activity];
    }

    public function test_provider_can_create_schedule_period(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/schedule", [
                'day_of_week' => 'saturday',
                'start_time' => '09:00',
                'end_time' => '13:00',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.day_of_week', 'saturday')
            ->assertJsonPath('data.crosses_midnight', false);

        $this->assertDatabaseHas('activity_schedule_periods', ['activity_id' => $activity->id, 'day_of_week' => 6]);
    }

    public function test_creating_overnight_period_sets_crosses_midnight_automatically(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/schedule", [
                'day_of_week' => 'saturday',
                'start_time' => '17:00',
                'end_time' => '05:00',
            ]);

        $response->assertCreated()->assertJsonPath('data.crosses_midnight', true);
    }

    public function test_identical_start_and_end_time_is_rejected(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/schedule", [
                'day_of_week' => 'saturday',
                'start_time' => '17:00',
                'end_time' => '17:00',
            ]);

        $response->assertStatus(422);
    }

    public function test_invalid_weekday_is_rejected(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/schedule", [
                'day_of_week' => 'not_a_real_day',
                'start_time' => '09:00',
                'end_time' => '13:00',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('day_of_week');
    }

    public function test_provider_can_update_period(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $period = $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/schedule/{$period->uuid}", ['instructions' => 'Updated instructions.']);

        $response->assertOk();
        $this->assertEquals('Updated instructions.', $response->json('data.instructions'));
    }

    public function test_updating_time_recomputes_crosses_midnight(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $period = $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/schedule/{$period->uuid}", [
                'start_time' => '17:00',
                'end_time' => '05:00',
            ]);

        $response->assertOk()->assertJsonPath('data.crosses_midnight', true);
    }

    public function test_provider_can_deactivate_period(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $period = $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/schedule/{$period->uuid}", ['status' => 'inactive']);

        $response->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_provider_can_delete_period(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $period = $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/provider/activities/{$activity->uuid}/schedule/{$period->uuid}");

        $response->assertOk();
        $this->assertSoftDeleted('activity_schedule_periods', ['id' => $period->id]);
    }

    public function test_provider_cannot_manage_another_providers_schedule(): void
    {
        [$userA, $providerA, $tokenA, $activityA] = $this->makeProviderActivity();
        [$userB, $providerB, $tokenB, $activityB] = $this->makeProviderActivity();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/v1/provider/activities/{$activityA->uuid}/schedule", [
                'day_of_week' => 'saturday',
                'start_time' => '09:00',
                'end_time' => '13:00',
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_update_period_belonging_to_a_different_activity_via_mismatched_uuids(): void
    {
        [$user, $provider, $token, $activityA] = $this->makeProviderActivity();
        $category = Category::create(['name' => 'Fishing', 'slug' => 'fishing-' . uniqid(), 'status' => 'active']);
        $activityB = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Second Activity',
            'slug' => 'second-activity-' . uniqid(),
        ]);
        $periodBelongingToB = $activityB->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activityA->uuid}/schedule/{$periodBelongingToB->uuid}", ['instructions' => 'Hacked instructions.']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('activity_schedule_periods', ['id' => $periodBelongingToB->id, 'instructions' => null]);
    }

    public function test_customer_token_cannot_access_provider_schedule_endpoints(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $customer = User::factory()->customer()->create();
        $customerToken = $customer->createToken('test', ['customer'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $customerToken)
            ->getJson("/api/v1/provider/activities/{$activity->uuid}/schedule");

        $response->assertStatus(403);
    }
}
