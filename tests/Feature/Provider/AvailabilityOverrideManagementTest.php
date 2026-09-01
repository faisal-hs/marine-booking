<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityOverrideManagementTest extends TestCase
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

    public function test_provider_can_create_single_date_blackout_override(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/overrides", [
                'date_from' => '2026-09-12',
                'date_to' => '2026-09-12',
                'is_unavailable' => true,
            ]);

        $response->assertCreated()->assertJsonPath('data.is_unavailable', true);
        $this->assertDatabaseHas('activity_availability_overrides', ['activity_id' => $activity->id, 'is_unavailable' => true]);
    }

    public function test_provider_can_create_date_range_override(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/overrides", [
                'date_from' => '2026-09-12',
                'date_to' => '2026-09-20',
                'is_unavailable' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.date_from', '2026-09-12')
            ->assertJsonPath('data.date_to', '2026-09-20');
    }

    public function test_date_to_before_date_from_is_rejected(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/overrides", [
                'date_from' => '2026-09-20',
                'date_to' => '2026-09-12',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('date_to');
    }

    public function test_provider_can_create_price_override_without_blackout(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/overrides", [
                'date_from' => '2026-09-12',
                'date_to' => '2026-09-12',
                'is_unavailable' => false,
                'price' => 600,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_unavailable', false);
        $this->assertEquals(600.0, $response->json('data.price'));
    }

    public function test_negative_override_price_is_rejected(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/overrides", [
                'date_from' => '2026-09-12',
                'date_to' => '2026-09-12',
                'price' => -50,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('price');
    }

    public function test_provider_can_update_override(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $override = $activity->availabilityOverrides()->create(['date_from' => '2026-09-12', 'date_to' => '2026-09-12', 'price' => 500]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/overrides/{$override->uuid}", ['price' => 550]);

        $response->assertOk();
        $this->assertEquals(550.0, $response->json('data.price'));
    }

    public function test_provider_can_delete_override(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $override = $activity->availabilityOverrides()->create(['date_from' => '2026-09-12', 'date_to' => '2026-09-12']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/provider/activities/{$activity->uuid}/overrides/{$override->uuid}");

        $response->assertOk();
        $this->assertDatabaseMissing('activity_availability_overrides', ['id' => $override->id]);
    }

    public function test_provider_cannot_manage_another_providers_overrides(): void
    {
        [$userA, $providerA, $tokenA, $activityA] = $this->makeProviderActivity();
        [$userB, $providerB, $tokenB, $activityB] = $this->makeProviderActivity();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/v1/provider/activities/{$activityA->uuid}/overrides", [
                'date_from' => '2026-09-12',
                'date_to' => '2026-09-12',
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_delete_override_belonging_to_a_different_activity_via_mismatched_uuids(): void
    {
        [$user, $provider, $token, $activityA] = $this->makeProviderActivity();
        $category = Category::create(['name' => 'Fishing', 'slug' => 'fishing-' . uniqid(), 'status' => 'active']);
        $activityB = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Second Activity',
            'slug' => 'second-activity-' . uniqid(),
        ]);
        $overrideBelongingToB = $activityB->availabilityOverrides()->create(['date_from' => '2026-09-12', 'date_to' => '2026-09-12']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/provider/activities/{$activityA->uuid}/overrides/{$overrideBelongingToB->uuid}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('activity_availability_overrides', ['id' => $overrideBelongingToB->id]);
    }
}
