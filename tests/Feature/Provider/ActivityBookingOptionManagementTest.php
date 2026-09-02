<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityBookingOptionManagementTest extends TestCase
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

    public function test_provider_can_list_booking_options(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/provider/activities/{$activity->uuid}/booking-options");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_provider_can_create_per_person_booking_option(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options", [
                'name' => 'Standard Snorkeling',
                'booking_model' => 'per_person',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.booking_model', 'per_person')
            ->assertJsonPath('data.slug', 'standard-snorkeling');

        $this->assertDatabaseHas('activity_booking_options', ['activity_id' => $activity->id, 'name' => 'Standard Snorkeling']);
    }

    public function test_creating_per_hour_option_requires_duration_bounds(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options", [
                'name' => 'Private Yacht',
                'booking_model' => 'per_hour',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['min_duration', 'max_duration']);
    }

    public function test_per_hour_min_duration_greater_than_max_is_rejected(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options", [
                'name' => 'Private Yacht',
                'booking_model' => 'per_hour',
                'min_duration' => 8,
                'max_duration' => 2,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('max_duration');
    }

    public function test_duplicate_name_within_same_activity_generates_unique_slug(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options", [
                'name' => 'Standard',
                'booking_model' => 'per_trip',
            ]);

        $response->assertCreated()->assertJsonPath('data.slug', 'standard-1');
    }

    public function test_setting_a_new_default_demotes_the_previous_default(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $first = $activity->bookingOptions()->create(['name' => 'First', 'slug' => 'first', 'booking_model' => 'per_trip', 'is_default' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/booking-options", [
                'name' => 'Second',
                'booking_model' => 'per_hour',
                'min_duration' => 2,
                'max_duration' => 8,
                'is_default' => true,
            ]);

        $response->assertCreated();
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($activity->bookingOptions()->where('name', 'Second')->first()->is_default);
    }

    public function test_provider_can_show_a_single_booking_option(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}");

        $response->assertOk()->assertJsonPath('data.name', 'Standard');
    }

    public function test_provider_can_update_booking_option(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}", ['description' => 'Updated description']);

        $response->assertOk()->assertJsonPath('data.description', 'Updated description');
    }

    public function test_provider_can_deactivate_booking_option(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}", ['is_active' => false]);

        $response->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_provider_can_delete_booking_option(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/provider/activities/{$activity->uuid}/booking-options/{$option->uuid}");

        $response->assertOk();
        $this->assertSoftDeleted('activity_booking_options', ['id' => $option->id]);
    }

    public function test_provider_cannot_manage_another_providers_booking_options(): void
    {
        [$userA, $providerA, $tokenA, $activityA] = $this->makeProviderActivity();
        [$userB, $providerB, $tokenB, $activityB] = $this->makeProviderActivity();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/v1/provider/activities/{$activityA->uuid}/booking-options", [
                'name' => 'Hacked Option',
                'booking_model' => 'per_trip',
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_update_option_belonging_to_a_different_activity_via_mismatched_uuids(): void
    {
        [$user, $provider, $token, $activityA] = $this->makeProviderActivity();
        $category = Category::create(['name' => 'Fishing', 'slug' => 'fishing-' . uniqid(), 'status' => 'active']);
        $activityB = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Second Activity',
            'slug' => 'second-activity-' . uniqid(),
        ]);
        $optionBelongingToB = $activityB->bookingOptions()->create(['name' => 'B Option', 'slug' => 'b-option', 'booking_model' => 'per_trip']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activityA->uuid}/booking-options/{$optionBelongingToB->uuid}", ['description' => 'Hacked']);

        $response->assertStatus(404);
    }

    public function test_customer_token_cannot_access_provider_booking_option_endpoints(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();
        $customer = User::factory()->customer()->create();
        $customerToken = $customer->createToken('test', ['customer'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $customerToken)
            ->getJson("/api/v1/provider/activities/{$activity->uuid}/booking-options");

        $response->assertStatus(403);
    }
}
