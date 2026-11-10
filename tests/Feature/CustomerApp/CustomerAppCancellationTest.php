<?php

namespace Tests\Feature\CustomerApp;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Payments\MockPaymentGateway;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * A customer cancelling. The trip is Saturday 21 November at 09:00 Riyadh, which is 06:00 UTC. More than 24 hours before: everything comes back;
 * more than 12: half; closer: nothing. The refund is the backend's, worked out from what was paid.
 */
class CustomerAppCancellationTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private $customer;

    private $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider();
        [$this->user, $this->customer, $this->token] = $this->signedInCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A booking made and paid through the app. */
    private function paid(array $over = [], ?string $token = null): int
    {
        return $this->ca($token ?? $this->token, 'POST', '/bookings', $over + ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1,
            'contact_name' => 'Ahmed', 'contact_phone' => '+966501234567', 'payment_method' => 'card'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.booking_id');
    }

    private function cancel(int|string $id, array $body = [], ?string $token = null)
    {
        return $this->ca($token ?? $this->token, 'POST', "/bookings/{$id}/cancel", $body);
    }

    // --- The windows ------------------------------------------------------------------------------------------------

    public function test_the_refund_follows_the_policy_at_every_boundary(): void
    {
        $cases = [
            'three days before' => ['2026-11-18 06:00:00', 1500, 'refunded'],
            'a minute over 24 hours' => ['2026-11-20 05:59:00', 1500, 'refunded'],
            'exactly 24 hours' => ['2026-11-20 06:00:00', 750, 'partially_refunded'],
            'a minute over 12 hours' => ['2026-11-20 17:59:00', 750, 'partially_refunded'],
            'exactly 12 hours' => ['2026-11-20 18:00:00', 0, 'succeeded'],
            'three hours before' => ['2026-11-21 03:00:00', 0, 'succeeded'],
        ];

        foreach ($cases as $when => [$now, $refund, $paymentStatus]) {
            Carbon::setTestNow('2026-11-15 12:00:00');
            $id = $this->paid();
            Carbon::setTestNow($now);

            $r = $this->cancel($id, ['reason' => 'Change of plans'])->assertOk();

            $this->assertSame(['Booking cancelled', ['id' => $id, 'status' => 'cancelled', 'refund_amount' => $refund]], [$r->json('message'), $r->json('data')], $when);
            $payment = Payment::where('reservation_id', $id)->firstOrFail();
            $this->assertEquals($refund, $payment->refunded_amount, $when);
            $this->assertSame($paymentStatus, $payment->status->value, $when);
        }
    }

    public function test_once_the_trip_has_started_it_can_no_longer_be_cancelled(): void
    {
        $id = $this->paid();
        Carbon::setTestNow('2026-11-21 06:00:01');

        $this->cancel($id)->assertStatus(409)->assertJsonPath('code', 'BOOKING_NOT_CANCELLABLE');

        $this->assertSame('confirmed', Reservation::find($id)->status->value);
        $this->assertEquals(0, Payment::firstOrFail()->refunded_amount);
    }

    public function test_the_policy_is_configuration_and_the_refund_follows_it(): void
    {
        config(['customer_app.cancellation.full_refund_hours' => 48, 'customer_app.cancellation.partial_refund_hours' => 24, 'customer_app.cancellation.partial_refund_percent' => 25]);
        $id = $this->paid();
        Carbon::setTestNow('2026-11-19 12:00:00');   // 42 hours before: past the 48 but within the 24

        $this->assertEquals(375, $this->cancel($id)->json('data.refund_amount'));

        config(['customer_app.cancellation.partial_refund_percent' => 0]);
        Carbon::setTestNow('2026-11-15 12:00:00');
        $second = $this->paid();
        Carbon::setTestNow('2026-11-19 12:00:00');
        $this->assertEquals(0, $this->cancel($second)->json('data.refund_amount'), 'No partial window at all');
    }

    public function test_the_refund_is_a_share_of_what_was_actually_paid_fee_and_vat_included(): void
    {
        config(['customer_app.service_fee_percent' => 3, 'customer_app.vat_percent' => 15]);
        $full = $this->paid();
        Carbon::setTestNow('2026-11-18 06:00:00');
        $this->assertEqualsWithDelta(1776.75, $this->cancel($full)->json('data.refund_amount'), 0.001);

        Carbon::setTestNow('2026-11-15 12:00:00');
        $half = $this->paid();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->assertEqualsWithDelta(888.38, $this->cancel($half)->json('data.refund_amount'), 0.001);
    }

    public function test_a_coupon_booking_is_refunded_what_was_paid_not_the_list_price(): void
    {
        Coupon::create(['provider_id' => $this->a['provider']->id, 'code' => 'SUMMER20', 'type' => 'percent', 'value' => 20, 'is_active' => true]);
        $id = $this->paid(['coupon_code' => 'SUMMER20']);
        Carbon::setTestNow('2026-11-18 06:00:00');

        $this->assertEquals(1200, $this->cancel($id)->json('data.refund_amount'));
    }

    public function test_the_refund_is_worked_out_on_what_is_still_refundable_not_on_the_original_payment(): void
    {
        // An admin already gave 300 of the 1,500 back.
        $full = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed');
        $this->makePayment($full, 1500, 300);
        Carbon::setTestNow('2026-11-18 06:00:00');

        $this->assertEquals(1200, $this->cancel($full)->json('data.refund_amount'), 'The 1,200 left, not 1,500');
        $payment = Payment::where('reservation_id', $full)->firstOrFail();
        $this->assertEquals(1500, $payment->refunded_amount, 'Never more than was paid');
        $this->assertSame('refunded', $payment->status->value);

        $half = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed');
        $this->makePayment($half, 1500, 300);
        Carbon::setTestNow('2026-11-20 12:00:00');   // the 50% window

        $this->assertEquals(600, $this->cancel($half)->json('data.refund_amount'), 'Half of the 1,200 left');
        $this->assertEquals(900, Payment::where('reservation_id', $half)->firstOrFail()->refunded_amount);
    }

    // --- What cancelling does -----------------------------------------------------------------------------------------

    public function test_it_records_who_cancelled_and_why_and_tells_both_the_booking_and_the_refund(): void
    {
        $id = $this->paid();
        Carbon::setTestNow('2026-11-18 06:00:00');

        $this->cancel($id, ['reason' => '  Change of plans  '])->assertOk();

        $res = Reservation::find($id);
        $this->assertSame(['cancelled', 'Change of plans', 'customer', $this->user->id], [$res->status->value, $res->cancellation_reason, $res->cancellation_source->value, $res->cancelled_by_user_id]);
        $this->assertNotNull($res->cancelled_at);
        $types = $this->user->notifications()->get()->pluck('data.type')->all();
        $this->assertContains('reservation.cancelled', $types);
        $this->assertContains('payment.refunded', $types);
        $detail = $this->ca($this->token, 'GET', "/bookings/{$id}")->json('data');
        $this->assertSame(['cancelled', 'Change of plans', 1500, false], [$detail['status'], $detail['cancellation_reason'], $detail['refund_amount'], $detail['can_cancel']]);
    }

    public function test_the_reason_is_optional(): void
    {
        $id = $this->paid();

        $this->cancel($id)->assertOk();

        $this->assertNull(Reservation::find($id)->cancellation_reason);
    }

    public function test_cancelling_gives_back_the_places_and_the_coupon_use(): void
    {
        $this->a['activity']->update(['max_participants' => 1]);
        Coupon::create(['provider_id' => $this->a['provider']->id, 'code' => 'SUMMER20', 'type' => 'percent', 'value' => 20, 'usage_limit' => 1, 'is_active' => true]);
        [, , $other] = $this->signedInCustomer();
        $id = $this->paid(['coupon_code' => 'SUMMER20']);
        $this->ca($other, 'POST', '/bookings', ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1, 'contact_name' => 'Other', 'contact_phone' => '+966501234567',
            'payment_method' => 'card', 'coupon_code' => 'SUMMER20'], ['Idempotency-Key' => 'o1'])->assertStatus(409);   // full

        $this->cancel($id)->assertOk();

        $this->ca($other, 'POST', '/bookings', ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1, 'contact_name' => 'Other', 'contact_phone' => '+966501234567',
            'payment_method' => 'card', 'coupon_code' => 'SUMMER20'], ['Idempotency-Key' => 'o2'])->assertCreated()->assertJsonPath('data.total', 1200);
    }

    public function test_a_booking_still_waiting_for_its_payment_can_be_cancelled_with_nothing_to_refund(): void
    {
        $id = $this->paid(['simulate' => 'async']);

        $r = $this->cancel($id)->assertOk();

        $this->assertSame(['cancelled', 0], [$r->json('data.status'), $r->json('data.refund_amount')]);
        $this->assertSame('cancelled', Reservation::find($id)->status->value);
        $this->assertSame(0, $this->user->notifications()->get()->where('data.type', 'payment.refunded')->count());
    }

    // --- When it cannot be cancelled --------------------------------------------------------------------------------------

    public function test_cancelling_twice_is_a_conflict_and_refunds_only_once(): void
    {
        $id = $this->paid();
        Carbon::setTestNow('2026-11-18 06:00:00');

        $this->cancel($id)->assertOk();
        $again = $this->cancel($id)->assertStatus(409);

        $this->assertSame('BOOKING_NOT_CANCELLABLE', $again->json('code'));
        $this->assertEquals(1500, Payment::firstOrFail()->refunded_amount, 'Refunded once');
        $this->assertSame(1, $this->user->notifications()->get()->where('data.type', 'payment.refunded')->count());
    }

    public function test_a_finished_cancelled_or_expired_booking_cannot_be_cancelled(): void
    {
        foreach (['completed' => '2026-11-01', 'cancelled' => self::NEXT_SATURDAY, 'expired' => self::NEXT_SATURDAY] as $status => $date) {
            $id = $this->makeBooking($this->a, $this->customer, $date, $status);

            $r = $this->cancel($id)->assertStatus(409);

            $this->assertSame('BOOKING_NOT_CANCELLABLE', $r->json('code'));
            $this->assertStringContainsString($status, $r->json('message'));
        }
    }

    public function test_a_hold_that_ran_out_is_expired_not_cancellable(): void
    {
        $id = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending', 1500, ['hold_expires_at' => now()->subMinute()->toDateTimeString()]);

        $this->cancel($id)->assertStatus(409)->assertJsonPath('code', 'BOOKING_NOT_CANCELLABLE');
    }

    public function test_another_customers_booking_is_not_found_and_stays_as_it_was(): void
    {
        [, $other, $otherToken] = $this->signedInCustomer();
        $theirs = $this->makeBooking($this->a, $other, self::NEXT_SATURDAY, 'confirmed');
        $this->makePayment($theirs, 1500);

        foreach ([$theirs, 99999, 'abc'] as $id) {
            $this->cancel($id)->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        }

        $this->assertSame('confirmed', Reservation::find($theirs)->status->value);
        $this->assertEquals(0, Payment::firstOrFail()->refunded_amount);
        $this->cancel($theirs, [], $otherToken)->assertOk();
    }

    public function test_a_reason_that_is_too_long_is_refused_and_nothing_changes(): void
    {
        $id = $this->paid();

        $this->cancel($id, ['reason' => str_repeat('x', 501)])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertSame('confirmed', Reservation::find($id)->status->value);
    }

    // --- When the refund fails --------------------------------------------------------------------------------------------

    public function test_if_the_refund_fails_the_booking_is_not_cancelled(): void
    {
        $id = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed');
        $this->makePayment($id, 1500);
        $this->app->bind(PaymentGatewayInterface::class, fn () => new class extends MockPaymentGateway
        {
            public function refund(Payment $payment, ?float $amount = null): array
            {
                throw new \RuntimeException('The gateway refused the refund');
            }
        });
        Carbon::setTestNow('2026-11-18 06:00:00');

        $this->cancel($id)->assertStatus(502)->assertJsonPath('code', 'REFUND_FAILED');

        $this->assertSame('confirmed', Reservation::find($id)->status->value, 'All together or not at all');
        $this->assertEquals(0, Payment::firstOrFail()->refunded_amount);
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_cancelling_is_limited_to_ten_a_minute(): void
    {
        foreach (range(1, 10) as $i) {
            $this->cancel(99999)->assertNotFound();
        }

        $this->cancel(99999)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
