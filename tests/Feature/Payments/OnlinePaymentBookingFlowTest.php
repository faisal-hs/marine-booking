<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOnlinePayments;
use Tests\Support\FakeTabby;
use Tests\Support\FakeTamara;
use Tests\TestCase;

/**
 * What the app sees when it pays for a booking with Tabby or Tamara: the checkout address on the first answer, on a retry of the SAME request (the same Idempotency-Key: a timeout, a
 * double tap, a request that died half way), and on `POST /bookings/{id}/pay`, which is how the app gets the address again; and why a payment is "unavailable" while debugging.
 */
class OnlinePaymentBookingFlowTest extends TestCase
{
    use BuildsOnlinePayments;
    use RefreshDatabase;

    private FakeTabby $tabby;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOnline();
        $this->enableTabby();
        $this->tabby = FakeTabby::install();
    }

    protected function tearDown(): void
    {
        $this->tearDownOnline();

        parent::tearDown();
    }

    private function pay(int $id, array $body = [], ?string $token = null)
    {
        return $this->ca($token ?? $this->token, 'POST', "/bookings/{$id}/pay", $body);
    }

    // --- The same request sent again ---------------------------------------------------------------------------------------------

    public function test_the_same_booking_request_sent_again_still_says_where_to_pay(): void
    {
        $first = $this->bookWith('tabby', 2, [], 'one-key')->assertCreated();
        $again = $this->bookWith('tabby', 2, [], 'one-key')->assertSuccessful();

        $this->assertSame('https://checkout.tabby.test/?sessionId=sess_1', $first->json('data.redirect_url'));
        $this->assertSame($first->json('data.redirect_url'), $again->json('data.redirect_url'), 'The retry gets the same checkout, not null');
        $this->assertSame([$first->json('data.booking_id'), $first->json('data.payment_id')], [$again->json('data.booking_id'), $again->json('data.payment_id')]);
        $this->assertCount(1, $this->tabby->calls('/api/v2/checkout'), 'One session at Tabby however many times the request is sent');
        $this->assertSame(1, Reservation::count());
    }

    public function test_a_request_that_died_after_the_booking_was_made_is_answered_with_its_checkout_on_the_retry(): void
    {
        $this->bookWith('tabby', 2, [], 'died-key')->assertCreated();
        // Whatever the first answer was, the booking and its waiting payment are saved: the retry finds them.
        $retry = $this->bookWith('tabby', 2, [], 'died-key')->assertSuccessful();

        $this->assertSame('pending', $retry->json('data.payment_status'));
        $this->assertNotNull($retry->json('data.redirect_url'));
    }

    public function test_a_retry_after_the_checkout_failed_or_ran_out_has_no_address_to_offer(): void
    {
        $this->bookWith('tabby', 2, [], 'k1')->assertCreated();

        Payment::query()->update(['expires_at' => now()->subMinute()]);
        $this->assertNull($this->bookWith('tabby', 2, [], 'k1')->assertSuccessful()->json('data.redirect_url'), 'A checkout that ran out is not offered');

        Payment::query()->update(['expires_at' => now()->addMinutes(10), 'status' => PaymentStatus::Failed->value]);
        $this->assertNull($this->bookWith('tabby', 2, [], 'k1')->assertSuccessful()->json('data.redirect_url'), 'Nor one that failed');
    }

    public function test_whatever_gateway_sends_the_customer_somewhere_its_address_is_kept_for_a_retry(): void
    {
        config(['payments.gateways.tabby.enabled' => false]);

        $first = $this->bookWith('card', 2, ['simulate' => 'async'], 'mock-key')->assertCreated();
        $again = $this->bookWith('card', 2, ['simulate' => 'async'], 'mock-key')->assertSuccessful();

        $this->assertNotNull($first->json('data.redirect_url'));
        $this->assertSame($first->json('data.redirect_url'), $again->json('data.redirect_url'));
    }

    public function test_a_payment_model_that_cannot_hold_the_checkout_address_fails_loudly_instead_of_losing_it(): void
    {
        // An older Payment model (without checkout_url among its fillable attributes) is what silently produced `redirect_url: null` with a perfectly good answer from Tabby.
        Payment::unguard(false);
        $payment = new class extends Payment {
            protected $table = 'payments';

            protected $fillable = ['reservation_id', 'gateway', 'amount', 'currency', 'status', 'payment_id', 'provider_reference', 'raw_payload', 'reference_id', 'uuid'];
        };
        $this->app->bind(\App\Repositories\Contracts\PaymentRepositoryInterface::class, fn () => new class($payment) extends \App\Repositories\Eloquent\PaymentRepository {
            public function __construct(Payment $model)
            {
                parent::__construct($model);
            }
        });
        config(['app.debug' => true]);

        $r = $this->bookWith('tabby');

        $this->assertSame(500, $r->status());
        $this->assertStringContainsString('out of date', (string) $r->getContent());
    }

    // --- POST /bookings/{id}/pay ------------------------------------------------------------------------------------------------------

    public function test_the_app_gets_the_checkout_address_again_and_it_is_the_same_checkout(): void
    {
        $id = $this->bookWith('tabby')->assertCreated()->json('data.booking_id');

        $r = $this->pay($id)->assertOk();

        $this->assertSame('Payment started', $r->json('message'));
        $this->assertSame(['pending', 'https://checkout.tabby.test/?sessionId=sess_1'], [$r->json('data.payment_status'), $r->json('data.redirect_url')]);
        $this->assertSame(['booking_id', 'reference', 'status', 'total', 'payment_id', 'payment_status', 'redirect_url'], array_keys($r->json('data')), 'The shape of a new booking');
        $this->assertCount(1, $this->tabby->calls('/api/v2/checkout'));
        $this->assertSame(1, Payment::count());
    }

    public function test_locally_the_pay_endpoint_takes_the_mocks_simulate_hint_as_booking_does(): void
    {
        config(['payments.gateways.tabby.enabled' => false]);
        $id = $this->bookWith('card', 2, ['simulate' => 'async'])->assertCreated()->json('data.booking_id');

        $this->pay($id, ['simulate' => 'async'])->assertOk()->assertJsonPath('data.payment_status', 'pending');
        $this->pay($id, ['simulate' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('simulate');
        $this->pay($id, ['simulate' => 'sync_success'])->assertOk()->assertJsonPath('data.payment_status', 'succeeded');
        $this->assertSame('confirmed', Reservation::findOrFail($id)->status->value);
    }

    public function test_after_a_decline_paying_again_makes_a_new_checkout(): void
    {
        $id = $this->bookWith('tabby')->assertCreated()->json('data.booking_id');
        $this->tabby->reject($this->tabby->id());
        $this->postJson('/api/v1/webhooks/payments/tabby', ['id' => $this->tabby->id(), 'status' => 'REJECTED', 'order' => ['reference_id' => $this->payment()->reference_id]], ['X-Tabby-Webhook-Secret' => 'tabby-hook-secret'])->assertOk();

        $r = $this->pay($id)->assertOk();

        $this->assertSame('https://checkout.tabby.test/?sessionId=sess_2', $r->json('data.redirect_url'));
        $this->assertSame(2, Payment::count());
    }

    public function test_the_method_can_be_changed_and_the_old_checkout_is_closed(): void
    {
        $id = $this->bookWith('tabby')->assertCreated()->json('data.booking_id');

        $this->pay($id, ['payment_method' => 'card'])->assertOk();

        $first = Payment::orderBy('id')->first();
        $this->assertSame(['tabby', 'failed', 'cancelled'], [$first->gateway, $first->status->value, $first->failure_code]);
    }

    public function test_a_booking_that_is_paid_cancelled_or_out_of_time_cannot_be_paid(): void
    {
        $id = $this->bookWith('tabby')->assertCreated()->json('data.booking_id');
        $reservation = Reservation::findOrFail($id);

        $reservation->update(['hold_expires_at' => now()->subMinute()]);
        $this->pay($id)->assertStatus(409)->assertJsonPath('code', 'BOOKING_NOT_PAYABLE');

        $reservation->update(['hold_expires_at' => now()->addMinutes(10), 'status' => 'confirmed']);
        $this->pay($id)->assertStatus(409)->assertJsonPath('code', 'BOOKING_NOT_PAYABLE');

        $reservation->update(['status' => 'cancelled']);
        $this->pay($id)->assertStatus(409)->assertJsonPath('code', 'BOOKING_NOT_PAYABLE');
    }

    public function test_only_the_customers_own_booking_can_be_paid_and_only_by_a_customer(): void
    {
        $id = $this->bookWith('tabby')->assertCreated()->json('data.booking_id');
        [, , $other] = $this->signedInCustomer(['phone' => '0555000111']);

        $this->pay($id, [], $other)->assertStatus(404);
        $this->pay(999999)->assertStatus(404);
        $this->ca(null, 'POST', "/bookings/{$id}/pay")->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->ca($this->a['token'], 'POST', "/bookings/{$id}/pay")->assertForbidden();
    }

    public function test_a_method_that_does_not_exist_or_that_the_activity_does_not_accept_is_refused(): void
    {
        $id = $this->bookWith('card', 2, ['simulate' => 'async'])->assertCreated()->json('data.booking_id');

        $this->pay($id, ['payment_method' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->a['activity']->update(['allows_tabby' => false]);
        $this->pay($id, ['payment_method' => 'tabby'])->assertStatus(422);
    }

    public function test_when_the_gateway_is_down_the_booking_is_kept_so_the_customer_can_try_again(): void
    {
        $id = $this->bookWith('tabby')->assertCreated()->json('data.booking_id');
        Payment::query()->update(['expires_at' => now()->subMinute()]);   // the checkout ran out: a new one is needed
        $this->tabby->mode = 'down';

        $this->pay($id)->assertStatus(503)->assertJsonPath('code', 'PAYMENT_UNAVAILABLE');
        $this->assertSame('pending', Reservation::findOrFail($id)->status->value, 'Not let go: it is still waiting');

        $this->tabby->mode = 'ok';
        $this->pay($id)->assertOk()->assertJsonPath('data.redirect_url', 'https://checkout.tabby.test/?sessionId=sess_2');
    }

    // --- Why a payment is unavailable ---------------------------------------------------------------------------------------------------

    public function test_while_debugging_the_answer_says_why_a_gateway_is_unavailable_and_in_production_it_does_not(): void
    {
        FakeTamara::install();
        $this->enableTamara(['notification_token' => '']);

        config(['app.debug' => true]);
        $debug = $this->bookWith('tamara')->assertStatus(503);
        $this->assertSame('PAYMENT_UNAVAILABLE', $debug->json('code'));
        $this->assertSame('TAMARA is enabled but is missing: TAMARA_NOTIFICATION_TOKEN.', $debug->json('errors.reason.0'));

        config(['app.debug' => false]);
        $quiet = $this->bookWith('tamara', 2, [], 'second')->assertStatus(503);
        $this->assertSame([], (array) $quiet->json('errors'), 'A customer is never told');
    }

    public function test_the_reason_for_an_unreachable_gateway_is_given_while_debugging(): void
    {
        $tamara = FakeTamara::install();
        $this->enableTamara();
        $tamara->mode = 'down';
        config(['app.debug' => true]);

        $this->bookWith('tamara')->assertStatus(503)->assertJsonPath('errors.reason.0', 'tamara create checkout: the gateway answered 503');
    }

    public function test_a_gateway_that_is_not_enabled_in_production_says_so_while_debugging(): void
    {
        config(['payments.gateways.tabby.enabled' => false, 'app.debug' => true]);
        $this->app['env'] = 'production';

        $this->bookWith('tabby')->assertStatus(503)->assertJsonPath('errors.reason.0', 'TABBY is not enabled: set TABBY_ENABLED=true and its credentials (see config/payments.php).');
    }

    public function test_a_hold_that_has_run_out_by_the_time_of_the_retry_is_still_answered(): void
    {
        $this->bookWith('tabby', 2, [], 'late-key')->assertCreated();
        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $retry = $this->bookWith('tabby', 2, [], 'late-key')->assertSuccessful();

        $this->assertNull($retry->json('data.redirect_url'), 'The checkout ran out with the hold');
    }
}
