<?php

namespace Tests\Feature\Customer;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCustomerToken(): array
    {
        $user = User::factory()->customer()->create();
        \App\Models\Customer::create(['user_id' => $user->id]);
        $token = $user->createToken('test', ['customer'])->plainTextToken;

        return [$user, $token];
    }

    protected function makePublishedActivityWithPerPersonOption(): array
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
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
        $option->pricingRules()->create(['participant_type' => 'child', 'price' => 750]);

        return [$activity, $option];
    }

    public function test_customer_can_create_a_reservation(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05', // a Saturday
                'participants' => ['adult' => 2, 'child' => 1],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $this->assertEquals(3750.0, $response->json('data.total'));

        $this->assertDatabaseHas('reservations', ['activity_id' => $activity->id, 'status' => 'pending']);
    }

    public function test_reservation_is_rejected_when_activity_not_available_on_that_date(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();
        // 2026-09-06 is a Sunday — the activity only has a Saturday period.

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'schedule_period' => $activity->schedulePeriods()->first()->uuid,
                'reservation_date' => '2026-09-06',
                'participants' => ['adult' => 1],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('reservation_date');
    }

    public function test_reservation_is_rejected_when_blacked_out_by_an_override(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();
        $activity->availabilityOverrides()->create(['date_from' => '2026-09-05', 'date_to' => '2026-09-05', 'is_unavailable' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 1],
            ]);

        $response->assertStatus(422);
    }

    public function test_reservation_is_rejected_when_pricing_is_missing(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();
        // 'infant' has no pricing rule configured at all.

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['infant' => 1],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('reservations', ['activity_id' => $activity->id]);
    }

    public function test_reservation_is_rejected_when_capacity_is_exceeded(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();
        $option->update(['max_participants' => 4]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 5],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('participants');
    }

    public function test_two_reservations_that_together_exceed_capacity_are_correctly_rejected(): void
    {
        [$userA, $tokenA] = $this->makeCustomerToken();
        [$userB, $tokenB] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();
        $option->update(['max_participants' => 4]);

        $this->app['auth']->forgetGuards();

        $firstResponse = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 3],
            ]);
        $firstResponse->assertCreated();

        $this->app['auth']->forgetGuards();

        $secondResponse = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 2],
            ]);

        $secondResponse->assertStatus(422)->assertJsonValidationErrors('participants');
    }

    public function test_customer_can_list_own_reservations(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 1],
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/customer/reservations');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_customer_cannot_view_another_customers_reservation(): void
    {
        [$userA, $tokenA] = $this->makeCustomerToken();
        [$userB, $tokenB] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();

        $this->app['auth']->forgetGuards();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 1],
            ]);
        $reservationUuid = $createResponse->json('data.uuid');

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->getJson("/api/v1/customer/reservations/{$reservationUuid}");

        $response->assertStatus(404);
    }

    public function test_customer_can_cancel_own_pending_reservation(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 1],
            ]);
        $reservationUuid = $createResponse->json('data.uuid');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservationUuid}/cancel", ['reason' => 'Change of plans']);

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cannot_cancel_an_already_cancelled_reservation(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        [$activity, $option] = $this->makePublishedActivityWithPerPersonOption();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 1],
            ]);
        $reservationUuid = $createResponse->json('data.uuid');

        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson("/api/v1/customer/reservations/{$reservationUuid}/cancel");

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservationUuid}/cancel");

        $response->assertStatus(422);
    }

    public function test_provider_token_cannot_access_customer_reservation_endpoints(): void
    {
        $providerUser = User::factory()->provider()->create();
        Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $token = $providerUser->createToken('test', ['provider'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/customer/reservations');

        $response->assertStatus(403);
    }
}
