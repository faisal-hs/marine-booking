<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityPricingRuleManagementTest extends TestCase
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

    public function test_provider_can_list_pricing_rules(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}/pricing-rules");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_provider_can_create_pricing_rule(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}/pricing-rules", [
                'participant_type' => 'adult',
                'price' => 1500,
            ]);

        $response->assertCreated();
        $this->assertEquals(1500.0, $response->json('data.price'));
    }

    public function test_negative_price_is_rejected(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_trip']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}/pricing-rules", [
                'price' => -100,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('price');
    }

    public function test_duplicate_active_rule_for_same_dimensions_is_rejected(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}/pricing-rules", [
                'participant_type' => 'adult',
                'price' => 1600,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('participant_type');
    }

    public function test_duplicate_check_does_not_block_different_participant_types(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}/pricing-rules", [
                'participant_type' => 'child',
                'price' => 750,
            ]);

        $response->assertCreated();
    }

    public function test_inactive_rule_does_not_block_a_new_active_rule_for_same_dimensions(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500, 'is_active' => false]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}/pricing-rules", [
                'participant_type' => 'adult',
                'price' => 1600,
            ]);

        $response->assertCreated();
    }

    public function test_schedule_period_from_a_different_activity_is_rejected(): void
    {
        [$user, $provider, $token, $activityA] = $this->makeProviderActivity();
        $category = Category::create(['name' => 'Fishing', 'slug' => 'fishing-' . uniqid(), 'status' => 'active']);
        $activityB = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Second Activity',
            'slug' => 'second-activity-' . uniqid(),
        ]);
        $periodOnB = $activityB->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $optionOnA = $activityA->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_trip']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activityA->uuid}/booking-options/{$optionOnA->uuid}/pricing-rules", [
                'price' => 8000,
                'schedule_period_id' => $periodOnB->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('schedule_period_id');
    }

    public function test_provider_can_update_pricing_rule(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_trip']);
        $rule = $option->pricingRules()->create(['price' => 8000]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}/pricing-rules/{$rule->uuid}", ['price' => 8500]);

        $response->assertOk();
        $this->assertEquals(8500.0, $response->json('data.price'));
    }

    public function test_provider_can_delete_pricing_rule(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_trip']);
        $rule = $option->pricingRules()->create(['price' => 8000]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}/pricing-rules/{$rule->uuid}");

        $response->assertOk();
        $this->assertDatabaseMissing('activity_pricing_rules', ['id' => $rule->id]);
    }

    public function test_cannot_manage_pricing_rule_belonging_to_a_sibling_booking_option(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $optionA = $activity->bookingOptions()->create(['name' => 'Option A', 'slug' => 'option-a', 'booking_model' => 'per_trip']);
        $optionB = $activity->bookingOptions()->create(['name' => 'Option B', 'slug' => 'option-b', 'booking_model' => 'per_trip']);
        $ruleBelongingToB = $optionB->pricingRules()->create(['price' => 8000]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$optionA->uuid}/pricing-rules/{$ruleBelongingToB->uuid}", ['price' => 999]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('activity_pricing_rules', ['id' => $ruleBelongingToB->id, 'price' => 8000.00]);
    }

    public function test_provider_cannot_manage_another_providers_pricing_rules(): void
    {
        [$userA, $providerA, $tokenA, $activityA] = $this->makeProviderActivity();
        [$userB, $providerB, $tokenB, $activityB] = $this->makeProviderActivity();
        $optionOnA = $activityA->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_trip']);

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/v1/provider/activities/{$activityA->uuid}/booking-options/{$optionOnA->uuid}/pricing-rules", [
                'price' => 8000,
            ]);

        $response->assertStatus(403);
    }
}
