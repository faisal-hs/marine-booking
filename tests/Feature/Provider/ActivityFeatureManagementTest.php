<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityFeatureManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProviderActivity(?Category $category = null): array
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;
        $category ??= Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
        ]);

        return [$user, $provider, $token, $category, $activity];
    }

    protected function seedDivingFeatures(Category $category): void
    {
        FeatureDefinition::create([
            'category_id' => $category->id,
            'key' => 'dive_depth',
            'label' => 'Dive Depth',
            'type' => 'number',
            'is_required' => true,
            'sort_order' => 1,
        ]);
        FeatureDefinition::create([
            'category_id' => $category->id,
            'key' => 'certification_required',
            'label' => 'Certification Required',
            'type' => 'boolean',
            'is_required' => true,
            'sort_order' => 2,
        ]);
        FeatureDefinition::create([
            'category_id' => $category->id,
            'key' => 'equipment_included',
            'label' => 'Equipment Included',
            'type' => 'multi_select',
            'options' => [['key' => 'bcd', 'label' => 'BCD'], ['key' => 'regulator', 'label' => 'Regulator']],
            'sort_order' => 3,
        ]);
        FeatureDefinition::create([
            'category_id' => $category->id,
            'key' => 'boat_type',
            'label' => 'Boat Type',
            'type' => 'select',
            'options' => [['key' => 'speedboat', 'label' => 'Speedboat'], ['key' => 'sailboat', 'label' => 'Sailboat']],
            'sort_order' => 4,
        ]);
    }

    public function test_provider_can_sync_valid_feature_values(): void
    {
        [$user, $provider, $token, $category, $activity] = $this->makeProviderActivity();
        $this->seedDivingFeatures($category);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/features", [
                'features' => [
                    'dive_depth' => 18,
                    'certification_required' => true,
                    'equipment_included' => ['bcd', 'regulator'],
                    'boat_type' => 'speedboat',
                ],
            ]);

        $response->assertOk()
            ->assertJsonCount(4, 'data.features');

        $this->assertDatabaseHas('activity_feature_values', ['activity_id' => $activity->id, 'value_number' => 18.00]);
        $this->assertDatabaseHas('activity_feature_values', ['activity_id' => $activity->id, 'value_boolean' => 1]);
        $this->assertEquals(4, $activity->featureValues()->count());
    }

    public function test_missing_required_feature_is_rejected(): void
    {
        [$user, $provider, $token, $category, $activity] = $this->makeProviderActivity();
        $this->seedDivingFeatures($category);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/features", [
                'features' => ['equipment_included' => ['bcd']],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['features.dive_depth', 'features.certification_required']);
    }

    public function test_invalid_select_option_is_rejected(): void
    {
        [$user, $provider, $token, $category, $activity] = $this->makeProviderActivity();
        $this->seedDivingFeatures($category);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/features", [
                'features' => ['dive_depth' => 18, 'certification_required' => true, 'boat_type' => 'yacht'],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['features.boat_type']);
    }

    public function test_feature_from_a_different_category_is_rejected(): void
    {
        [$user, $provider, $token, $categoryA, $activity] = $this->makeProviderActivity();
        $this->seedDivingFeatures($categoryA);

        $categoryB = Category::create(['name' => 'Fishing', 'slug' => 'fishing-' . uniqid(), 'status' => 'active']);
        FeatureDefinition::create([
            'category_id' => $categoryB->id,
            'key' => 'fishing_rod_provided',
            'label' => 'Fishing Rod Provided',
            'type' => 'boolean',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/features", [
                'features' => ['dive_depth' => 18, 'certification_required' => true, 'fishing_rod_provided' => true],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('activity_feature_values', ['activity_id' => $activity->id]);
    }

    public function test_provider_cannot_sync_features_on_another_providers_activity(): void
    {
        [$userA, $providerA, $tokenA, $categoryA, $activityA] = $this->makeProviderActivity();
        $this->seedDivingFeatures($categoryA);
        [$userB, $providerB, $tokenB] = $this->makeProviderActivity();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->putJson("/api/v1/provider/activities/{$activityA->uuid}/features", [
                'features' => ['dive_depth' => 10, 'certification_required' => false],
            ]);

        $response->assertStatus(403);
    }

    public function test_changing_category_clears_existing_feature_values(): void
    {
        [$user, $provider, $token, $categoryA, $activity] = $this->makeProviderActivity();
        $this->seedDivingFeatures($categoryA);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/features", [
                'features' => ['dive_depth' => 18, 'certification_required' => true],
            ])->assertOk();

        $this->assertEquals(2, $activity->featureValues()->count());

        $categoryB = Category::create(['name' => 'Fishing', 'slug' => 'fishing-' . uniqid(), 'status' => 'active']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}", [
                'category_id' => $categoryB->id,
            ])->assertOk();

        $this->assertEquals(0, $activity->featureValues()->count());
    }
}
