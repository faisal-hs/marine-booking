<?php

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentService = app(PaymentService::class);
    }

    protected function makePendingReservation(): Reservation
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

        // The schedule period above is Saturday-only (day_of_week => 6),
        // so the reservation date must fall on a Saturday too — computed
        // relative to today rather than hardcoded, so this never goes
        // stale as real time passes.
        return app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::now()->next(Carbon::SATURDAY), ['adult' => 2]);
    }

    // --- initiate() ---

    public function test_sync_success_confirms_the_reservation(): void
    {
        $reservation = $this->makePendingReservation();

        $result = $this->paymentService->initiate($reservation, ['simulate' => 'sync_success']);

        $this->assertEquals(PaymentStatus::Succeeded, $result['payment']->status);
        $this->assertNotNull($result['payment']->paid_at);
        $this->assertNull($result['redirect_url']);
        $this->assertEquals(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_sync_failure_leaves_reservation_pending_for_retry(): void
    {
        $reservation = $this->makePendingReservation();

        $result = $this->paymentService->initiate($reservation, ['simulate' => 'sync_failure']);

        $this->assertEquals(PaymentStatus::Failed, $result['payment']->status);
        $this->assertNotNull($result['payment']->failure_reason);
        $this->assertEquals(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    public function test_async_charge_stays_pending_with_a_redirect_url(): void
    {
        $reservation = $this->makePendingReservation();

        $result = $this->paymentService->initiate($reservation, ['simulate' => 'async']);

        $this->assertEquals(PaymentStatus::Pending, $result['payment']->status);
        $this->assertNotNull($result['redirect_url']);
        $this->assertEquals(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    public function test_cannot_initiate_payment_for_a_non_pending_reservation(): void
    {
        $reservation = $this->makePendingReservation();
        $reservation->update(['status' => 'cancelled']);

        $this->expectException(ValidationException::class);

        $this->paymentService->initiate($reservation);
    }

    public function test_cannot_initiate_payment_for_an_expired_hold(): void
    {
        $reservation = $this->makePendingReservation();
        $reservation->update(['hold_expires_at' => now()->subMinute()]);

        $this->expectException(ValidationException::class);

        $this->paymentService->initiate($reservation);
    }

    public function test_payment_amount_is_snapshotted_from_the_reservation_total(): void
    {
        $reservation = $this->makePendingReservation();

        $result = $this->paymentService->initiate($reservation, ['simulate' => 'async']);

        $this->assertEquals((float) $reservation->total, (float) $result['payment']->amount);
        $this->assertEquals($reservation->currency, $result['payment']->currency);
    }

    // --- Idempotency ---

    public function test_mark_succeeded_is_idempotent(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'async']);
        $payment = $result['payment'];

        $this->paymentService->markSucceeded($payment);
        $paidAtFirst = $payment->fresh()->paid_at;

        $this->paymentService->markSucceeded($payment->fresh());
        $paidAtSecond = $payment->fresh()->paid_at;

        $this->assertEquals($paidAtFirst->toIso8601String(), $paidAtSecond->toIso8601String());
    }

    public function test_mark_failed_never_overwrites_an_already_succeeded_payment(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'sync_success']);
        $payment = $result['payment'];

        $this->paymentService->markFailed($payment, 'late failure attempt');

        $this->assertEquals(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    // --- Refunds ---

    public function test_full_refund(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'sync_success']);

        $refunded = $this->paymentService->refund($result['payment']);

        $this->assertEquals(PaymentStatus::Refunded, $refunded->status);
        $this->assertEquals((float) $result['payment']->amount, (float) $refunded->refunded_amount);
    }

    public function test_partial_refund(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'sync_success']);
        $fullAmount = (float) $result['payment']->amount;

        $refunded = $this->paymentService->refund($result['payment'], $fullAmount / 2);

        $this->assertEquals(PaymentStatus::PartiallyRefunded, $refunded->status);
        $this->assertEquals(round($fullAmount / 2, 2), (float) $refunded->refunded_amount);
    }

    public function test_cannot_refund_a_pending_payment(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'async']);

        $this->expectException(ValidationException::class);

        $this->paymentService->refund($result['payment']);
    }

    public function test_cannot_refund_more_than_the_remaining_balance(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'sync_success']);

        $this->expectException(ValidationException::class);

        $this->paymentService->refund($result['payment'], (float) $result['payment']->amount + 1);
    }

    // --- Webhook flow ---

    public function test_webhook_confirms_an_async_payment(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'async']);
        $payment = $result['payment'];

        $body = json_encode(['provider_reference' => $payment->provider_reference, 'status' => 'succeeded']);
        $signature = hash_hmac('sha256', $body, config('payments.mock.webhook_secret'));

        $request = \Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X-Mock-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        $request->headers->set('X-Mock-Signature', $signature);

        $updatedPayment = $this->paymentService->handleWebhook($request);

        $this->assertEquals(PaymentStatus::Succeeded, $updatedPayment->status);
        $this->assertEquals(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'async']);

        $body = json_encode(['provider_reference' => $result['payment']->provider_reference, 'status' => 'succeeded']);
        $request = \Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $request->headers->set('X-Mock-Signature', 'wrong-signature');

        $this->expectException(ValidationException::class);

        $this->paymentService->handleWebhook($request);
    }

    public function test_webhook_rejects_unknown_provider_reference(): void
    {
        $body = json_encode(['provider_reference' => 'mock_does-not-exist', 'status' => 'succeeded']);
        $signature = hash_hmac('sha256', $body, config('payments.mock.webhook_secret'));
        $request = \Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $request->headers->set('X-Mock-Signature', $signature);

        $this->expectException(ValidationException::class);

        $this->paymentService->handleWebhook($request);
    }

    // --- Payment model ---

    public function test_reference_id_is_auto_generated_and_unique(): void
    {
        $reservation = $this->makePendingReservation();
        $result = $this->paymentService->initiate($reservation, ['simulate' => 'sync_success']);

        $this->assertNotEmpty($result['payment']->reference_id);
        $this->assertStringStartsWith('PAY-', $result['payment']->reference_id);

        $reservation2 = $this->makePendingReservation();
        $result2 = $this->paymentService->initiate($reservation2, ['simulate' => 'sync_success']);

        $this->assertNotEquals($result['payment']->reference_id, $result2['payment']->reference_id);
    }
}
