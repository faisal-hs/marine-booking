<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProviderWithActivityAndReservation(): array
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $providerToken = $providerUser->createToken('test', ['provider'])->plainTextToken;

        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create();
        \App\Models\Customer::create(['user_id' => $customerUser->id]);
        $customerToken = $customerUser->createToken('test', ['customer'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $customerToken)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 2],
            ]);

        return [$providerToken, $activity];
    }

    public function test_provider_can_list_reservations_across_their_activities(): void
    {
        [$providerToken, $activity] = $this->makeProviderWithActivityAndReservation();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $providerToken)
            ->getJson('/api/v1/provider/reservations');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_provider_can_list_reservations_for_a_specific_activity(): void
    {
        [$providerToken, $activity] = $this->makeProviderWithActivityAndReservation();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $providerToken)
            ->getJson("/api/v1/provider/activities/{$activity->uuid}/reservations");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_provider_cannot_see_another_providers_activity_reservations(): void
    {
        [$providerTokenA, $activityA] = $this->makeProviderWithActivityAndReservation();

        $this->app['auth']->forgetGuards();

        $providerUserB = User::factory()->provider()->create();
        Provider::create(['user_id' => $providerUserB->id, 'business_name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $providerTokenB = $providerUserB->createToken('test', ['provider'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $providerTokenB)
            ->getJson("/api/v1/provider/activities/{$activityA->uuid}/reservations");

        $response->assertStatus(403);
    }

    public function test_customer_token_cannot_access_provider_reservation_endpoints(): void
    {
        [$providerToken, $activity] = $this->makeProviderWithActivityAndReservation();

        $customerUser = User::factory()->customer()->create();
        \App\Models\Customer::create(['user_id' => $customerUser->id]);
        $customerToken = $customerUser->createToken('test', ['customer'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $customerToken)
            ->getJson('/api/v1/provider/reservations');

        $response->assertStatus(403);
    }
}
