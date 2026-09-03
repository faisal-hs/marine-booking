<?php

namespace Tests\Feature\Customer;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCustomerToken(): array
    {
        $user = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $user->id]);
        $token = $user->createToken('test', ['customer'])->plainTextToken;

        return [$user, $customer, $token];
    }

    protected function makeActivity(): Activity
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        return Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
    }

    protected function makeReservation(Customer $customer, Activity $activity, string $status = 'completed'): Reservation
    {
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);

        return Reservation::create([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => now()->subDay()->toDateString(),
            'status' => $status,
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 1500,
            'total' => 1500,
            'pricing_breakdown' => [],
        ]);
    }

    public function test_customer_with_completed_reservation_can_review(): void
    {
        [$user, $customer, $token] = $this->makeCustomerToken();
        $activity = $this->makeActivity();
        $this->makeReservation($customer, $activity, 'completed');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 5, 'comment' => 'Amazing!']);

        $response->assertCreated()->assertJsonPath('data.rating', 5);
        $this->assertEquals(5.00, (float) $activity->fresh()->average_rating);
        $this->assertEquals(1, $activity->fresh()->reviews_count);
    }

    public function test_customer_without_a_reservation_cannot_review(): void
    {
        [$user, $customer, $token] = $this->makeCustomerToken();
        $activity = $this->makeActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 5]);

        $response->assertStatus(422)->assertJsonValidationErrors('activity');
    }

    public function test_customer_with_only_a_confirmed_not_completed_reservation_cannot_review(): void
    {
        [$user, $customer, $token] = $this->makeCustomerToken();
        $activity = $this->makeActivity();
        $this->makeReservation($customer, $activity, 'confirmed');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 5]);

        $response->assertStatus(422);
    }

    public function test_customer_cannot_review_the_same_activity_twice(): void
    {
        [$user, $customer, $token] = $this->makeCustomerToken();
        $activity = $this->makeActivity();
        $this->makeReservation($customer, $activity, 'completed');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 4]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 5]);

        $response->assertStatus(422)->assertJsonValidationErrors('activity');
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        [$user, $customer, $token] = $this->makeCustomerToken();
        $activity = $this->makeActivity();
        $this->makeReservation($customer, $activity, 'completed');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 6]);

        $response->assertStatus(422)->assertJsonValidationErrors('rating');
    }

    public function test_customer_can_update_own_review(): void
    {
        [$user, $customer, $token] = $this->makeCustomerToken();
        $activity = $this->makeActivity();
        $this->makeReservation($customer, $activity, 'completed');
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 3]);
        $reviewUuid = $createResponse->json('data.uuid');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/customer/reviews/{$reviewUuid}", ['rating' => 5, 'comment' => 'Updated!']);

        $response->assertOk()->assertJsonPath('data.rating', 5);
        $this->assertEquals(5.00, (float) $activity->fresh()->average_rating);
    }

    public function test_customer_can_delete_own_review(): void
    {
        [$user, $customer, $token] = $this->makeCustomerToken();
        $activity = $this->makeActivity();
        $this->makeReservation($customer, $activity, 'completed');
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 4]);
        $reviewUuid = $createResponse->json('data.uuid');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->deleteJson("/api/v1/customer/reviews/{$reviewUuid}");

        $response->assertOk();
        $this->assertDatabaseMissing('reviews', ['uuid' => $reviewUuid]);
        $this->assertEquals(0, $activity->fresh()->reviews_count);
    }

    public function test_customer_cannot_update_another_customers_review(): void
    {
        [$userA, $customerA, $tokenA] = $this->makeCustomerToken();
        [$userB, $customerB, $tokenB] = $this->makeCustomerToken();
        $activity = $this->makeActivity();
        $this->makeReservation($customerA, $activity, 'completed');
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 4]);
        $reviewUuid = $createResponse->json('data.uuid');

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->putJson("/api/v1/customer/reviews/{$reviewUuid}", ['rating' => 1]);

        $response->assertStatus(404);
    }

    public function test_average_rating_is_correctly_computed_across_multiple_reviews(): void
    {
        [$userA, $customerA, $tokenA] = $this->makeCustomerToken();
        [$userB, $customerB, $tokenB] = $this->makeCustomerToken();
        $activity = $this->makeActivity();
        $this->makeReservation($customerA, $activity, 'completed');
        $this->makeReservation($customerB, $activity, 'completed');

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 5]);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson('/api/v1/customer/reviews', ['activity' => $activity->uuid, 'rating' => 3]);

        $this->assertEquals(4.00, (float) $activity->fresh()->average_rating);
        $this->assertEquals(2, $activity->fresh()->reviews_count);
    }
}
