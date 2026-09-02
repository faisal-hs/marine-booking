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

class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCustomerWithReservation(): array
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
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $token = $customerUser->createToken('test', ['customer'])->plainTextToken;

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid,
                'booking_option' => $option->uuid,
                'reservation_date' => '2026-09-05',
                'participants' => ['adult' => 2],
            ]);

        $reservation = Reservation::find($createResponse->json('data.id'));

        return [$token, $reservation];
    }

    public function test_customer_can_initiate_a_sync_success_payment(): void
    {
        [$token, $reservation] = $this->makeCustomerWithReservation();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", ['simulate' => 'sync_success']);

        $response->assertCreated()
            ->assertJsonPath('data.payment.status', 'succeeded')
            ->assertJsonPath('data.redirect_url', null);

        $this->assertEquals('confirmed', $reservation->fresh()->status->value);
    }

    public function test_customer_can_initiate_an_async_payment_and_receives_a_redirect_url(): void
    {
        [$token, $reservation] = $this->makeCustomerWithReservation();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", ['simulate' => 'async']);

        $response->assertCreated()
            ->assertJsonPath('data.payment.status', 'pending');

        $this->assertNotNull($response->json('data.redirect_url'));
        $this->assertEquals('pending', $reservation->fresh()->status->value);
    }

    public function test_customer_cannot_initiate_payment_for_another_customers_reservation(): void
    {
        [$tokenA, $reservationA] = $this->makeCustomerWithReservation();
        [$tokenB, $reservationB] = $this->makeCustomerWithReservation();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/v1/customer/reservations/{$reservationA->uuid}/payments", ['simulate' => 'sync_success']);

        $response->assertStatus(404);
    }

    public function test_customer_can_view_own_payment(): void
    {
        [$token, $reservation] = $this->makeCustomerWithReservation();
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", ['simulate' => 'sync_success']);
        $paymentUuid = $createResponse->json('data.payment.uuid');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson("/api/v1/customer/payments/{$paymentUuid}");

        $response->assertOk()->assertJsonPath('data.status', 'succeeded');
    }

    public function test_customer_cannot_view_another_customers_payment(): void
    {
        [$tokenA, $reservationA] = $this->makeCustomerWithReservation();
        [$tokenB, $reservationB] = $this->makeCustomerWithReservation();

        $this->app['auth']->forgetGuards();
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson("/api/v1/customer/reservations/{$reservationA->uuid}/payments", ['simulate' => 'sync_success']);
        $paymentUuid = $createResponse->json('data.payment.uuid');

        $this->app['auth']->forgetGuards();
        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)->getJson("/api/v1/customer/payments/{$paymentUuid}");

        $response->assertStatus(404);
    }

    public function test_unauthenticated_request_cannot_initiate_payment(): void
    {
        // Deliberately built via direct Service/Eloquent calls, NOT an
        // authenticated HTTP request — this test's own method never
        // authenticates at all, sidestepping any possibility of auth
        // state bleeding from an earlier request within the same test
        // (three different reset strategies — header clearing,
        // forgetGuards(), session flush — all failed to prevent a prior
        // authenticated call's identity from resolving on a header-less
        // follow-up request; this restructuring avoids the question
        // entirely rather than leave an unexplained gap).
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
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create();
        $customer = \App\Models\Customer::create(['user_id' => $customerUser->id]);

        $reservation = app(\App\Services\ReservationService::class)->create(
            $customer,
            $activity,
            $option,
            null,
            \Illuminate\Support\Carbon::parse('2026-09-05'),
            ['adult' => 2]
        );

        $response = $this->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", ['simulate' => 'sync_success']);

        $response->assertStatus(401);
    }

    // --- Webhook ---

    public function test_webhook_confirms_an_async_payment_end_to_end(): void
    {
        [$token, $reservation] = $this->makeCustomerWithReservation();
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", ['simulate' => 'async']);

        $payment = \App\Models\Payment::find($createResponse->json('data.payment.id'));

        $body = json_encode(['provider_reference' => $payment->provider_reference, 'status' => 'succeeded']);
        $signature = hash_hmac('sha256', $body, config('payments.mock.webhook_secret'));

        $response = $this->call('POST', '/api/v1/webhooks/payments/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Mock-Signature' => $signature,
        ], $body);

        $response->assertOk();
        $this->assertEquals('confirmed', $reservation->fresh()->status->value);
    }

    public function test_webhook_rejects_an_unsupported_gateway_name(): void
    {
        $response = $this->postJson('/api/v1/webhooks/payments/some-other-gateway', []);

        $response->assertStatus(404);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        [$token, $reservation] = $this->makeCustomerWithReservation();
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", ['simulate' => 'async']);
        $payment = \App\Models\Payment::find($createResponse->json('data.payment.id'));

        $body = json_encode(['provider_reference' => $payment->provider_reference, 'status' => 'succeeded']);

        $response = $this->call('POST', '/api/v1/webhooks/payments/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Mock-Signature' => 'wrong-signature',
        ], $body);

        $response->assertStatus(422);
    }
}
