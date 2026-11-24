<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsOnlinePayments;
use Tests\Support\FakeTabby;
use Tests\TestCase;

/**
 * Tabby, from the booking to the money: the checkout, the webhook, the verification with Tabby, the capture, what happens when the customer is too late or says no, duplicates,
 * refunds. Tabby's API is stood in for by FakeTabby, which behaves as documented: so this proves OUR side, and not that Tabby's servers agree (see docs/PAYMENTS.md).
 */
class TabbyPaymentTest extends TestCase
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

    private function webhook(string $id, string $status = 'AUTHORIZED', ?string $secret = 'tabby-hook-secret', ?string $reference = null)
    {
        return $this->postJson('/api/v1/webhooks/payments/tabby', ['id' => $id, 'status' => $status, 'order' => ['reference_id' => $reference ?? $this->payment()->reference_id]], $secret === null ? [] : ['X-Tabby-Webhook-Secret' => $secret]);
    }

    /** A booking paid with Tabby, up to the point where the customer is at Tabby's page. */
    private function started(): string
    {
        $this->bookWith('tabby')->assertCreated();

        return $this->tabby->id();
    }

    /** The customer approves and Tabby tells us. */
    private function paid(): string
    {
        $id = $this->started();
        $this->tabby->approve($id);
        $this->webhook($id)->assertOk();

        return $id;
    }

    // --- Starting the payment --------------------------------------------------------------------------------------------------

    public function test_a_booking_paid_with_tabby_gets_a_checkout_to_go_to(): void
    {
        $r = $this->bookWith('tabby')->assertCreated();

        $this->assertSame('https://checkout.tabby.test/?sessionId=sess_1', $r->json('data.redirect_url'));
        $this->assertSame(['pending', $this->payment()->reference_id], [$r->json('data.payment_status'), $r->json('data.payment_id')]);
        $this->assertSame('pending', $this->reservation()->status->value);
    }

    public function test_the_payment_is_tied_to_the_booking_and_remembers_its_checkout(): void
    {
        $this->bookWith('tabby')->assertCreated();
        $payment = $this->payment();

        $this->assertSame(['tabby', $this->reservation()->id, 'tabby_pay_1', 'tabby_pay_1', 'SAR', PaymentStatus::Pending], [$payment->gateway, $payment->reservation_id, $payment->payment_id, $payment->provider_reference, $payment->currency, $payment->status]);
        $this->assertEquals(3000, $payment->amount);
        $this->assertEquals((float) $this->reservation()->total, (float) $payment->amount, 'The amount is the booking\'s total, as it was when payment started');
        $this->assertSame('https://checkout.tabby.test/?sessionId=sess_1', $payment->checkout_url);
        $this->assertEquals($this->reservation()->hold_expires_at, $payment->expires_at, 'It can be paid until the hold runs out');
    }

    public function test_the_session_is_what_tabby_documents(): void
    {
        $this->bookWith('tabby')->assertCreated();
        $call = $this->tabby->calls('/api/v2/checkout')[0];
        $payment = $this->payment();
        $body = $call['body'];

        $this->assertSame('Bearer pk_test_public', $call['auth'], 'The session is made with the public key');
        $this->assertSame(['3000.00', 'SAR', $payment->reference_id], [$body['payment']['amount'], $body['payment']['currency'], $body['payment']['order']['reference_id']]);
        $this->assertSame('SA', $body['merchant_code']);
        $this->assertSame('+966501234567', $body['payment']['buyer']['phone']);
        $this->assertSame(['Ahmed Al-Harbi', 'ahmed@example.test'], [$body['payment']['buyer']['name'], $body['payment']['buyer']['email']]);
        $this->assertSame('3000.00', $body['payment']['order']['items'][0]['unit_price'], 'The one line adds up to the amount');
        foreach (['success', 'cancel', 'failure'] as $outcome) {
            $this->assertStringContainsString("/api/v1/payments/{$payment->uuid}/return/{$outcome}", $body['merchant_urls'][$outcome]);
            $this->assertStringContainsString('signature=', $body['merchant_urls'][$outcome], 'The addresses the customer returns to are signed');
        }
    }

    public function test_the_customers_phone_is_given_to_tabby_in_international_form(): void
    {
        $this->bookWith('tabby', 2, ['contact_phone' => '+966 50 123 4567'])->assertCreated();

        $this->assertSame('+966501234567', $this->tabby->calls('/api/v2/checkout')[0]['body']['payment']['buyer']['phone']);
    }

    public function test_a_session_tabby_refuses_is_a_failed_payment_with_its_reason_and_the_booking_stays_to_try_again(): void
    {
        $this->tabby->mode = 'reject';

        $r = $this->bookWith('tabby')->assertCreated();

        $this->assertNull($r->json('data.redirect_url'));
        $this->assertSame('failed', $r->json('data.payment_status'));
        $this->assertSame(['declined', 'The amount is too high for Tabby.'], [$this->payment()->failure_code, $this->payment()->failure_reason]);
        $this->assertSame('pending', $this->reservation()->status->value, 'The customer may try another way before the hold runs out');
    }

    public function test_when_tabby_cannot_be_reached_nothing_is_booked_or_charged(): void
    {
        $this->tabby->mode = 'down';

        $this->bookWith('tabby')->assertStatus(503)->assertJsonPath('code', 'PAYMENT_UNAVAILABLE');

        $this->assertSame(0, Payment::count(), 'No payment row is left for a charge that was never made');
        $this->assertSame('expired', $this->reservation()->status->value, 'The places are let go at once');
    }

    public function test_tabby_is_only_used_where_the_provider_accepts_it_for_the_activity(): void
    {
        $this->a['activity']->update(['allows_tabby' => false]);

        $this->bookWith('tabby')->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->assertCount(0, $this->tabby->calls('/api/v2/checkout'));
    }

    public function test_a_currency_tabby_does_not_take_is_refused_before_anything_is_sent(): void
    {
        $this->enableTabby(['currencies' => ['AED']]);

        $this->bookWith('tabby')->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->assertCount(0, $this->tabby->calls('/api/v2/checkout'));
    }

    // --- The customer pays: the webhook, the check, the capture --------------------------------------------------------------

    public function test_an_approval_is_checked_with_tabby_then_captured_and_the_booking_is_confirmed(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);

        $this->webhook($id)->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertSame([PaymentStatus::Succeeded, 'confirmed'], [$this->payment()->status, $this->reservation()->status->value]);
        $this->assertNotNull($this->payment()->paid_at);
        $this->assertCount(1, $this->tabby->calls('/captures'), 'Captured once');
        $this->assertSame('Bearer sk_test_secret', $this->tabby->calls('/captures')[0]['auth'], 'Everything after the session uses the secret key');
        $this->assertSame(['3000.00', $this->payment()->reference_id], [$this->tabby->calls('/captures')[0]['body']['amount'], $this->tabby->calls('/captures')[0]['body']['reference_id']]);
        $this->assertSame('CLOSED', $this->tabby->payments[$id]['status']);
    }

    public function test_nothing_is_settled_on_what_the_webhook_says_only_on_what_tabby_says(): void
    {
        $id = $this->started();   // the customer has NOT approved: Tabby says CREATED

        $this->webhook($id, 'AUTHORIZED')->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertSame([PaymentStatus::Pending, 'pending'], [$this->payment()->status, $this->reservation()->status->value]);
        $this->assertCount(0, $this->tabby->calls('/captures'), 'A forged "approved" captures nothing');
    }

    public function test_the_same_webhook_twice_settles_once(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);

        $this->webhook($id)->assertOk();
        $this->webhook($id)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->webhook($id, 'CLOSED')->assertOk()->assertJsonPath('data.status', 'succeeded');   // and the follow-up Tabby sends after a capture

        $this->assertCount(1, $this->tabby->calls('/captures'));
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentEvent::where('event_key', "{$id}:AUTHORIZED")->count());
        $this->assertSame('succeeded', PaymentEvent::where('event_key', "{$id}:AUTHORIZED")->value('result'));
    }

    public function test_a_notification_is_only_marked_done_once_the_payment_has_settled(): void
    {
        $id = $this->started();

        $this->webhook($id, 'AUTHORIZED')->assertOk()->assertJsonPath('data.status', 'pending');   // Tabby has not caught up: the customer has not approved yet
        $this->assertNull(PaymentEvent::where('event_key', "{$id}:AUTHORIZED")->value('result'), 'Not done: it found nothing decided');

        $this->tabby->approve($id);
        $this->webhook($id, 'AUTHORIZED')->assertOk()->assertJsonPath('data.status', 'succeeded');   // the same notification again, now it is true
        $this->assertSame('succeeded', PaymentEvent::where('event_key', "{$id}:AUTHORIZED")->value('result'));
    }

    public function test_a_notification_for_a_payment_that_is_settled_is_not_asked_of_tabby_again(): void
    {
        $id = $this->started();
        $this->tabby->reject($id);
        $this->webhook($id, 'REJECTED')->assertOk();
        $reads = count($this->tabby->calls('/api/v2/payments/'.$id));

        $this->webhook($id, 'REJECTED')->assertOk()->assertJsonPath('data.status', 'failed');   // sent again

        $this->assertCount($reads, $this->tabby->calls('/api/v2/payments/'.$id), 'Answered from the first: Tabby was not asked again');
    }

    public function test_two_webhooks_arriving_together_capture_once(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);
        $this->webhook($id)->assertOk();

        // The second one meets a payment that is already settled: no second capture, however it got there.
        app(PaymentService::class)->reconcile($this->payment());
        app(PaymentService::class)->reconcile($this->payment());

        $this->assertCount(1, $this->tabby->calls('/captures'));
    }

    public function test_a_webhook_that_does_not_carry_our_secret_is_refused_and_changes_nothing(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);
        $calls = count($this->tabby->calls);

        $this->webhook($id, 'AUTHORIZED', 'wrong-secret')->assertStatus(401);
        $this->webhook($id, 'AUTHORIZED', null)->assertStatus(401);
        $this->webhook($id, 'AUTHORIZED', '')->assertStatus(401);

        $this->assertSame([PaymentStatus::Pending, 'pending'], [$this->payment()->status, $this->reservation()->status->value]);
        $this->assertCount($calls, $this->tabby->calls, 'Tabby was not even asked: a notification that does not prove itself is not acted on');
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_no_secret_configured_means_no_webhook_is_accepted(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);
        $this->enableTabby(['webhook_secret' => '']);   // not fully configured: the gateway refuses everything, "no secret" included

        $this->postJson('/api/v1/webhooks/payments/tabby', ['id' => $id, 'status' => 'AUTHORIZED'], ['X-Tabby-Webhook-Secret' => ''])->assertStatus(503);
        $this->postJson('/api/v1/webhooks/payments/tabby', ['id' => $id, 'status' => 'AUTHORIZED'])->assertStatus(503);

        $this->assertSame(PaymentStatus::Pending, $this->payment()->status);
        $this->assertCount(0, $this->tabby->calls('/captures'));
    }

    public function test_a_webhook_for_a_payment_we_do_not_know_is_refused(): void
    {
        $this->started();

        $this->postJson('/api/v1/webhooks/payments/tabby', ['id' => 'nobody', 'status' => 'AUTHORIZED'], ['X-Tabby-Webhook-Secret' => 'tabby-hook-secret'])->assertStatus(422);
        $this->postJson('/api/v1/webhooks/payments/tabby', ['status' => 'AUTHORIZED'], ['X-Tabby-Webhook-Secret' => 'tabby-hook-secret'])->assertStatus(401);
    }

    public function test_a_webhook_for_a_gateway_that_is_not_switched_on_is_not_found(): void
    {
        config(['payments.gateways.tabby.enabled' => false]);

        $this->postJson('/api/v1/webhooks/payments/tabby', ['id' => 'x'], ['X-Tabby-Webhook-Secret' => 'tabby-hook-secret'])->assertStatus(404);
        $this->postJson('/api/v1/webhooks/payments/nonsense', [])->assertStatus(404);
    }

    public function test_a_declined_payment_fails_with_its_reason_and_the_customer_may_try_again(): void
    {
        $id = $this->started();
        $this->tabby->reject($id);

        $this->webhook($id, 'REJECTED')->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(['declined', 'pending'], [$this->payment()->failure_code, $this->reservation()->status->value]);
        $this->assertCount(0, $this->tabby->calls('/captures'));
    }

    public function test_a_payment_that_expired_at_tabby_fails_as_expired(): void
    {
        $id = $this->started();
        $this->tabby->expire($id);

        $this->webhook($id, 'EXPIRED')->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame('expired', $this->payment()->failure_code);
    }

    public function test_a_failed_payment_that_is_declined_again_stays_as_it_was(): void
    {
        $id = $this->started();
        $this->tabby->reject($id);
        $this->webhook($id, 'REJECTED')->assertOk();

        $this->webhook($id, 'REJECTED')->assertOk();   // sent again

        $this->assertSame(PaymentStatus::Failed, $this->payment()->status);
    }

    // --- What Tabby says is checked against what we asked ----------------------------------------------------------------------

    public function test_an_amount_that_is_not_the_one_asked_for_is_voided_and_never_captured(): void
    {
        $id = $this->started();
        $this->tabby->payments[$id]['amount'] = '1.00';
        $this->tabby->approve($id);

        $this->webhook($id)->assertOk();

        $this->assertSame(['mismatch', 'pending'], [$this->payment()->failure_code, $this->reservation()->status->value]);
        $this->assertCount(0, $this->tabby->calls('/captures'));
        $this->assertCount(1, $this->tabby->calls('/close'), 'The approval is closed, so nobody holds money for it');
    }

    public function test_a_currency_that_is_not_the_one_asked_for_is_refused(): void
    {
        $id = $this->started();
        $this->tabby->payments[$id]['currency'] = 'AED';
        $this->tabby->approve($id);

        $this->webhook($id)->assertOk();

        $this->assertSame('mismatch', $this->payment()->failure_code);
        $this->assertCount(0, $this->tabby->calls('/captures'));
    }

    public function test_a_payment_that_belongs_to_another_order_is_refused(): void
    {
        $id = $this->started();
        $this->tabby->payments[$id]['order']['reference_id'] = 'SOMEONE-ELSES-ORDER';
        $this->tabby->approve($id);

        $this->webhook($id)->assertOk();

        $this->assertSame('mismatch', $this->payment()->failure_code);
        $this->assertCount(0, $this->tabby->calls('/captures'));
    }

    // --- Too late: the booking is gone before the customer pays -----------------------------------------------------------------

    public function test_an_approval_after_the_hold_ran_out_is_voided_and_nobody_is_charged(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);
        Carbon::setTestNow(Carbon::now()->addMinutes(30));   // the hold (15 minutes) has run out

        $this->webhook($id)->assertOk();

        $this->assertSame(['late_payment', PaymentStatus::Failed], [$this->payment()->failure_code, $this->payment()->status]);
        $this->assertCount(0, $this->tabby->calls('/captures'), 'Nothing was taken');
        $this->assertCount(1, $this->tabby->calls('/close'));
        $this->assertNotSame('confirmed', $this->reservation()->fresh()->status->value);
    }

    public function test_an_approval_for_a_booking_the_customer_cancelled_is_voided(): void
    {
        $id = $this->started();
        $this->ca($this->token, 'POST', "/bookings/{$this->reservation()->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->tabby->approve($id);   // they approve at Tabby anyway, afterwards

        $this->webhook($id)->assertOk();

        $this->assertSame('cancelled', $this->reservation()->fresh()->status->value);
        $this->assertCount(0, $this->tabby->calls('/captures'));
        $this->assertSame('CLOSED', $this->tabby->payments[$id]['status'], 'Tabby holds nothing for it');
        $this->assertSame(PaymentStatus::Failed, $this->payment()->status);
    }

    public function test_cancelling_a_booking_closes_its_open_checkout(): void
    {
        $this->started();
        $this->ca($this->token, 'POST', "/bookings/{$this->reservation()->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();

        $this->assertSame(['failed', 'cancelled'], [$this->payment()->status->value, $this->payment()->failure_code]);
    }

    public function test_a_booking_whose_hold_runs_out_closes_its_open_checkout(): void
    {
        $this->started();
        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $this->artisan('reservations:expire-stale-holds')->assertSuccessful();

        $this->assertSame(['failed', 'expired'], [$this->payment()->status->value, $this->payment()->failure_code]);
    }

    public function test_money_taken_by_tabby_for_a_booking_that_lapsed_is_given_back(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);
        // Tabby captured it itself (auto-capture) while the booking was already gone.
        $this->tabby->payments[$id]['status'] = 'CLOSED';
        $this->tabby->payments[$id]['captures'][] = ['id' => 'c1', 'amount' => '3000.00'];
        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $this->webhook($id, 'CLOSED')->assertOk();

        $this->assertSame([PaymentStatus::Refunded, 'late_payment'], [$this->payment()->status, $this->payment()->failure_code]);
        $this->assertEquals(3000, $this->payment()->refunded_amount);
        $this->assertSame('3000.00', $this->tabby->calls('/refunds')[0]['body']['amount']);
    }

    // --- Recovery ---------------------------------------------------------------------------------------------------------------

    public function test_when_the_capture_cannot_be_made_the_booking_is_not_confirmed_and_it_is_tried_again(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);
        $this->tabby->mode = 'capture_down';

        $this->webhook($id)->assertStatus(502);   // Tabby is told to send it again
        $this->assertSame([PaymentStatus::Pending, 'pending'], [$this->payment()->status, $this->reservation()->status->value]);
        $this->assertSame(0, PaymentEvent::whereNotNull('result')->count(), 'The notification was not marked done');

        $this->tabby->mode = 'ok';
        $this->webhook($id)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('confirmed', $this->reservation()->status->value);
        $this->assertCount(1, $this->tabby->payments[$id]['captures'], 'Taken once, by the second try');
    }

    public function test_a_capture_that_tabby_refused_but_had_already_made_counts_as_made(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);
        $this->tabby->mode = 'capture_refused';
        // The capture before this one went through; the answer was lost, and now the same call is refused.
        $this->tabby->payments[$id]['status'] = 'CLOSED';
        $this->tabby->payments[$id]['captures'][] = ['id' => 'c1', 'amount' => '3000.00'];

        $this->webhook($id, 'CLOSED')->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertSame('confirmed', $this->reservation()->status->value);
    }

    public function test_the_reconciler_settles_a_payment_whose_webhook_never_came(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);   // no webhook arrives
        Carbon::setTestNow(Carbon::now()->addMinutes(3));

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame([PaymentStatus::Succeeded, 'confirmed'], [$this->payment()->status, $this->reservation()->status->value]);
    }

    public function test_the_reconciler_leaves_a_fresh_payment_and_one_still_undecided_alone(): void
    {
        $this->started();

        $this->artisan('payments:reconcile')->assertSuccessful();   // too young
        $this->assertCount(0, $this->tabby->calls('/api/v2/payments/tabby_pay_1'));

        Carbon::setTestNow(Carbon::now()->addMinutes(3));
        $this->artisan('payments:reconcile')->assertSuccessful();   // old enough, but the customer has not decided
        $this->assertSame(PaymentStatus::Pending, $this->payment()->status);
    }

    public function test_the_reconciler_goes_on_when_tabby_cannot_be_reached(): void
    {
        $this->started();
        Carbon::setTestNow(Carbon::now()->addMinutes(3));
        $this->tabby->mode = 'down';

        $this->artisan('payments:reconcile')->expectsOutputToContain('1 could not be checked')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $this->payment()->status);
    }

    // --- The customer comes back -------------------------------------------------------------------------------------------------

    private function returnTo(string $outcome, array $query = [], bool $signed = true): string
    {
        $url = URL::temporarySignedRoute('payments.return', now()->addDay(), ['payment' => $this->payment()->uuid, 'outcome' => $outcome]);

        return $signed ? $url.($query ? '&'.http_build_query($query) : '') : "/api/v1/payments/{$this->payment()->uuid}/return/{$outcome}";
    }

    public function test_coming_back_after_paying_confirms_even_before_the_webhook(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);

        $this->getJson($this->returnTo('success', ['payment_id' => $id]))->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame('confirmed', $this->reservation()->status->value);
        $this->webhook($id)->assertOk();   // the webhook that comes after changes nothing
        $this->assertCount(1, $this->tabby->calls('/captures'));
    }

    public function test_the_customer_is_sent_on_to_the_app_with_the_outcome(): void
    {
        config(['payments.return_urls.success' => 'marinebooking://paid', 'payments.return_urls.cancel' => 'marinebooking://cancelled', 'payments.return_urls.failure' => 'marinebooking://failed']);
        $id = $this->started();
        $this->tabby->approve($id);

        $r = $this->get($this->returnTo('success'));

        $r->assertRedirect();
        $this->assertStringStartsWith('marinebooking://paid?', $r->headers->get('Location'));
        parse_str((string) parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame([$this->reservation()->uuid, $this->payment()->reference_id, 'paid'], [$q['booking'], $q['payment'], $q['status']]);
    }

    public function test_pressing_cancel_at_tabby_cancels_the_payment_and_closes_the_checkout(): void
    {
        config(['payments.return_urls.cancel' => 'marinebooking://cancelled']);
        $this->started();

        $r = $this->get($this->returnTo('cancel'));

        $this->assertStringStartsWith('marinebooking://cancelled?', $r->headers->get('Location'));
        $this->assertSame(['failed', 'cancelled'], [$this->payment()->status->value, $this->payment()->failure_code]);
        $this->assertSame('pending', $this->reservation()->status->value, 'The booking is still held: the customer may pay another way');
    }

    public function test_the_return_trusts_tabby_over_the_customer(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);   // they say "cancel", but Tabby has the approval

        $this->getJson($this->returnTo('cancel'))->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame('confirmed', $this->reservation()->status->value);
    }

    public function test_a_return_address_that_is_not_signed_or_was_changed_is_refused(): void
    {
        $this->started();

        $this->getJson($this->returnTo('success', [], false))->assertStatus(403);
        $this->getJson(str_replace('/return/success', '/return/failure', $this->returnTo('success')))->assertStatus(403);
        $this->assertSame(PaymentStatus::Pending, $this->payment()->status);
    }

    public function test_a_return_made_while_tabby_cannot_be_reached_says_it_is_still_being_confirmed(): void
    {
        $this->started();
        $this->tabby->mode = 'down';

        $this->getJson($this->returnTo('success'))->assertOk()->assertJsonPath('data.status', 'pending');
    }

    // --- Duplicates and retries -------------------------------------------------------------------------------------------------

    public function test_asking_to_pay_again_while_the_checkout_is_live_gives_the_same_checkout(): void
    {
        $this->started();

        $again = $this->payAgain($this->reservation(), 'tabby')->assertCreated();

        $this->assertSame('https://checkout.tabby.test/?sessionId=sess_1', $again->json('data.redirect_url'));
        $this->assertCount(1, $this->tabby->calls('/api/v2/checkout'), 'One session, however many taps');
        $this->assertSame(1, Payment::count());
    }

    public function test_a_booking_that_is_paid_cannot_be_paid_again(): void
    {
        $this->paid();

        $this->payAgain($this->reservation(), 'tabby')->assertStatus(422);

        $this->assertSame(1, Payment::count());
        $this->assertCount(1, $this->tabby->calls('/api/v2/checkout'));
    }

    public function test_after_a_decline_the_customer_can_try_again_with_a_new_checkout(): void
    {
        $id = $this->started();
        $this->tabby->reject($id);
        $this->webhook($id, 'REJECTED')->assertOk();

        $again = $this->payAgain($this->reservation(), 'tabby')->assertCreated();

        $this->assertSame('https://checkout.tabby.test/?sessionId=sess_2', $again->json('data.redirect_url'));
        $this->assertSame(2, Payment::count());
        $this->tabby->approve($this->tabby->id(2));
        $this->webhook($this->tabby->id(2))->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('confirmed', $this->reservation()->status->value);
    }

    public function test_a_checkout_that_ran_out_is_closed_before_a_new_one_starts(): void
    {
        $this->started();
        Payment::query()->update(['expires_at' => now()->subMinute()]);

        $this->payAgain($this->reservation(), 'tabby')->assertCreated();

        $this->assertSame(['failed', 'expired'], [Payment::orderBy('id')->first()->status->value, Payment::orderBy('id')->first()->failure_code]);
        $this->assertSame(2, Payment::count());
    }

    public function test_asking_for_another_method_replaces_the_open_checkout_and_the_first_cannot_be_paid(): void
    {
        $id = $this->started();

        $this->payAgain($this->reservation(), 'card')->assertCreated();   // the default gateway (the mock outside production)

        $first = Payment::orderBy('id')->first();
        $this->assertSame(['tabby', 'failed', 'cancelled'], [$first->gateway, $first->status->value, $first->failure_code]);
        $this->tabby->approve($id);   // the customer approves the abandoned Tabby checkout anyway
        $this->webhook($id)->assertOk();
        $this->assertCount(0, $this->tabby->calls('/captures'), 'The abandoned checkout is voided, not charged');
    }

    // --- Refunds ----------------------------------------------------------------------------------------------------------------

    public function test_a_payment_is_refunded_through_tabby_in_full_or_in_part(): void
    {
        $id = $this->paid();
        $payment = $this->payment();

        $part = app(PaymentService::class)->refund($payment, 500);
        $this->assertSame([PaymentStatus::PartiallyRefunded, 500.0], [$part->status, (float) $part->refunded_amount]);

        $rest = app(PaymentService::class)->refund($part);
        $this->assertSame([PaymentStatus::Refunded, 3000.0], [$rest->status, (float) $rest->refunded_amount]);
        $this->assertSame(['500.00', '2500.00'], array_map(fn ($c) => $c['body']['amount'], $this->tabby->calls('/refunds')));
        $this->assertSame(['sk_test_secret'], array_values(array_unique(array_map(fn ($c) => substr($c['auth'], 7), $this->tabby->calls('/refunds')))));
        $this->assertCount(2, $this->tabby->payments[$id]['refunds']);
    }

    public function test_a_refund_tabby_refuses_changes_nothing_and_says_so(): void
    {
        $this->paid();
        $payment = $this->payment();
        $this->tabby->payments[$this->tabby->id()]['captures'] = [];   // Tabby holds no capture for it: it refuses the refund

        try {
            app(PaymentService::class)->refund($payment, 100);
            $this->fail('The refund should have been refused.');
        } catch (PaymentGatewayException $e) {
            $this->assertStringContainsString('tabby refused the refund', $e->getMessage());
        }

        $this->assertSame([PaymentStatus::Succeeded, 0.0], [$this->payment()->status, (float) $this->payment()->refunded_amount], 'Nothing was recorded as refunded');
    }

    public function test_a_payment_that_was_never_paid_cannot_be_refunded(): void
    {
        $this->started();

        $this->expectException(ValidationException::class);
        app(PaymentService::class)->refund($this->payment());
    }

    public function test_cancelling_a_paid_booking_refunds_the_customer_through_tabby(): void
    {
        $id = $this->paid();

        $this->ca($this->token, 'POST', "/bookings/{$this->reservation()->id}/cancel", ['reason' => 'Plans changed'])->assertOk();

        $refunds = $this->tabby->calls('/refunds');
        $this->assertNotEmpty($refunds, 'The policy refund goes back through Tabby');
        $this->assertSame($this->payment()->fresh()->refunded_amount, number_format((float) $refunds[0]['body']['amount'], 2, '.', ''));
        $this->assertContains($this->payment()->fresh()->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded]);
        $this->assertNotEmpty($this->tabby->payments[$id]['refunds']);
    }

    // --- Readiness, and what is never logged ---------------------------------------------------------------------------------------

    public function test_a_gateway_that_is_enabled_but_not_fully_set_up_is_refused_not_half_used(): void
    {
        $this->enableTabby(['secret_key' => '']);

        $this->bookWith('tabby')->assertStatus(503)->assertJsonPath('code', 'PAYMENT_UNAVAILABLE');

        $this->assertCount(0, $this->tabby->calls);
    }

    public function test_where_tabby_is_not_switched_on_and_nothing_is_real_the_method_works_through_the_mock_as_it_always_has(): void
    {
        config(['payments.gateways.tabby.enabled' => false, 'payments.default' => 'mock']);

        $this->bookWith('tabby', 2, ['simulate' => 'sync_success'])->assertCreated();

        $this->assertSame('mock', $this->payment()->gateway);
        $this->assertCount(0, $this->tabby->calls);
    }

    public function test_in_production_tabby_that_is_not_switched_on_is_refused_and_never_falls_back_to_the_mock(): void
    {
        config(['payments.gateways.tabby.enabled' => false]);
        $this->app['env'] = 'production';

        $this->bookWith('tabby')->assertStatus(503)->assertJsonPath('code', 'PAYMENT_UNAVAILABLE');

        $this->assertSame(0, Payment::count());
    }

    public function test_no_key_secret_or_customer_detail_is_ever_logged(): void
    {
        $logged = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info', 'warning', 'error', 'critical', 'debug')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = $message.' '.json_encode($context);
        });

        $id = $this->started();
        $this->tabby->payments[$id]['amount'] = '1.00';
        $this->tabby->approve($id);
        $this->webhook($id);
        $this->webhook($id, 'AUTHORIZED', 'wrong-secret');

        $all = implode("\n", $logged);
        $this->assertNotEmpty($logged);
        foreach (['sk_test_secret', 'pk_test_public', 'tabby-hook-secret', 'wrong-secret', 'ahmed@example.test', '0501234567', '+966501234567', 'Ahmed Al-Harbi'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }
}
