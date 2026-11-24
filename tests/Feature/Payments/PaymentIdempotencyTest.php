<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Events\ReservationConfirmed;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsOnlinePayments;
use Tests\Support\FakeTabby;
use Tests\TestCase;

/**
 * A payment settles once and a booking is confirmed once, even if two requests settle it at the same moment and each last read it as still waiting. The locks are the first guard
 * (see PaymentService::reconcile); this is the second: the database only lets a payment or a booking that is STILL pending be settled, and only the request that does it announces it.
 * (On MariaDB: without any lock, 12 simultaneous webhooks confirmed one booking 12 times; with the guard, once.)
 */
class PaymentIdempotencyTest extends TestCase
{
    use BuildsOnlinePayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOnline();
        $this->enableTabby();
        FakeTabby::install();
        $this->bookWith('tabby')->assertCreated();
    }

    protected function tearDown(): void
    {
        $this->tearDownOnline();

        parent::tearDown();
    }

    public function test_two_copies_of_the_same_payment_settle_it_once_and_confirm_the_booking_once(): void
    {
        Event::fake([ReservationConfirmed::class]);
        $first = Payment::findOrFail($this->payment()->id);
        $second = Payment::findOrFail($this->payment()->id);   // read at the same moment: both think it is pending

        app(PaymentService::class)->markSucceeded($first, ['via' => 'first']);
        $again = app(PaymentService::class)->markSucceeded($second, ['via' => 'second']);

        Event::assertDispatchedTimes(ReservationConfirmed::class, 1);
        $this->assertSame([PaymentStatus::Succeeded, 'confirmed'], [$again->status, $this->reservation()->status->value]);
        $this->assertSame('first', $this->payment()->raw_payload['via'], 'The second did not overwrite what the first recorded');
    }

    public function test_two_copies_of_the_same_booking_are_confirmed_once(): void
    {
        Event::fake([ReservationConfirmed::class]);
        $first = Reservation::findOrFail($this->reservation()->id);
        $second = Reservation::findOrFail($this->reservation()->id);

        app(ReservationService::class)->confirmPayment($first);
        $result = app(ReservationService::class)->confirmPayment($second);   // it still reads "pending"

        Event::assertDispatchedTimes(ReservationConfirmed::class, 1);
        $this->assertSame('confirmed', $result->status->value);
        $this->assertNotNull($this->reservation()->confirmed_at);
    }

    public function test_a_booking_that_is_not_pending_is_still_refused_as_it_always_was(): void
    {
        $this->reservation()->update(['status' => 'cancelled']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ReservationService::class)->confirmPayment($this->reservation()->fresh());
    }

    public function test_settling_a_payment_that_is_already_settled_changes_nothing(): void
    {
        $payment = $this->payment();
        app(PaymentService::class)->markSucceeded($payment);
        $paidAt = $this->payment()->paid_at;

        $this->travel(5)->minutes();
        app(PaymentService::class)->markSucceeded($this->payment());

        $this->assertEquals($paidAt, $this->payment()->paid_at, 'The time it was paid is the first time');
    }

    public function test_the_customer_reads_where_to_pay_and_why_a_payment_failed(): void
    {
        $this->app['auth']->forgetGuards();
        $read = fn () => $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->getJson("/api/v1/customer/payments/{$this->payment()->uuid}")->assertOk()->json('data');

        $open = $read();
        $this->assertSame(['pending', 'tabby', 'https://checkout.tabby.test/?sessionId=sess_1', null], [$open['status'], $open['gateway'], $open['checkout_url'], $open['failure_code']]);
        $this->assertNotNull($open['expires_at']);

        app(PaymentService::class)->markFailed($this->payment(), 'The gateway declined the payment.', 'declined');
        $failed = $read();
        $this->assertSame(['failed', 'declined', null], [$failed['status'], $failed['failure_code'], $failed['checkout_url']], 'Nowhere to pay once it is over');
    }
}
