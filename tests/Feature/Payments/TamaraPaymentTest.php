<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\PaymentService;
use App\Support\Jwt;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsOnlinePayments;
use Tests\Support\FakeTamara;
use Tests\TestCase;

/**
 * Tamara, from the booking to the money: the checkout, the signed notification, the check with Tamara, authorising and capturing, the customer who is too late or says no,
 * duplicates, refunds. Tamara's API is stood in for by FakeTamara, which behaves as documented: so this proves OUR side, and not that Tamara's servers agree (see docs/PAYMENTS.md).
 */
class TamaraPaymentTest extends TestCase
{
    use BuildsOnlinePayments;
    use RefreshDatabase;

    private const SECRET = 'tamara-notification-secret';

    private FakeTamara $tamara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOnline();
        $this->enableTamara();
        $this->tamara = FakeTamara::install();
    }

    protected function tearDown(): void
    {
        $this->tearDownOnline();

        parent::tearDown();
    }

    private function token(array $claims = [], string $secret = self::SECRET): string
    {
        return Jwt::signHs256($claims + ['iss' => 'Tamara', 'iat' => time(), 'exp' => time() + 3600], $secret);
    }

    /** A notification as Tamara sends it: the token as a bearer header. */
    private function notify(string $orderId, string $event = 'order_approved', ?string $token = null, bool $asQuery = false)
    {
        $token ??= $this->token();
        $uri = '/api/v1/webhooks/payments/tamara'.($asQuery ? '?tamaraToken='.$token : '');
        $headers = $asQuery || $token === '' ? [] : ['Authorization' => "Bearer {$token}"];

        return $this->postJson($uri, ['order_id' => $orderId, 'order_reference_id' => $this->payment()->reference_id, 'order_status' => 'approved', 'event_type' => $event], $headers);
    }

    private function started(): string
    {
        $this->bookWith('tamara')->assertCreated();

        return $this->tamara->id();
    }

    private function paid(): string
    {
        $id = $this->started();
        $this->tamara->approve($id);
        $this->notify($id)->assertOk();

        return $id;
    }

    // --- Starting the payment --------------------------------------------------------------------------------------------------

    public function test_a_booking_paid_with_tamara_gets_a_checkout_to_go_to(): void
    {
        $r = $this->bookWith('tamara')->assertCreated();

        $this->assertSame('https://checkout.tamara.test/checkout/chk-1', $r->json('data.redirect_url'));
        $this->assertSame(['pending', $this->payment()->reference_id], [$r->json('data.payment_status'), $r->json('data.payment_id')]);
        $this->assertSame('pending', $this->reservation()->status->value);
    }

    public function test_the_payment_is_tied_to_the_booking_and_remembers_its_checkout(): void
    {
        $this->bookWith('tamara')->assertCreated();
        $payment = $this->payment();

        $this->assertSame(['tamara', $this->reservation()->id, 'tamara-order-1', 'tamara-order-1', 'SAR', PaymentStatus::Pending], [$payment->gateway, $payment->reservation_id, $payment->payment_id, $payment->provider_reference, $payment->currency, $payment->status]);
        $this->assertEquals(3000, $payment->amount);
        $this->assertSame('https://checkout.tamara.test/checkout/chk-1', $payment->checkout_url);
        $this->assertEquals($this->reservation()->hold_expires_at, $payment->expires_at);
    }

    public function test_the_checkout_is_what_tamara_documents(): void
    {
        $this->bookWith('tamara')->assertCreated();
        $call = $this->tamara->calls('/checkout')[0];
        $payment = $this->payment();
        $b = $call['body'];

        $this->assertSame('Bearer tamara-api-token', $call['auth']);
        $this->assertSame([$payment->reference_id, $payment->reference_id, 'SA', 'PAY_BY_INSTALMENTS'], [$b['order_reference_id'], $b['order_number'], $b['country_code'], $b['payment_type']]);
        $this->assertEquals(['amount' => 3000.0, 'currency' => 'SAR'], $b['total_amount']);
        $this->assertSame(['Ahmed', 'Al-Harbi', '+966501234567', 'ahmed@example.test'], [$b['consumer']['first_name'], $b['consumer']['last_name'], $b['consumer']['phone_number'], $b['consumer']['email']]);
        $this->assertEquals(3000.0, $b['items'][0]['total_amount']['amount'], 'The one line adds up to the total');
        $this->assertSame(route('webhooks.payments', ['gateway' => 'tamara']), $b['merchant_url']['notification']);
        foreach (['success', 'cancel', 'failure'] as $outcome) {
            $this->assertStringContainsString("/api/v1/payments/{$payment->uuid}/return/{$outcome}", $b['merchant_url'][$outcome]);
            $this->assertStringContainsString('signature=', $b['merchant_url'][$outcome]);
        }
    }

    public function test_a_checkout_tamara_refuses_is_a_failed_payment_with_its_reason_and_the_booking_stays(): void
    {
        $this->tamara->mode = 'checkout_refused';

        $r = $this->bookWith('tamara')->assertCreated();

        $this->assertNull($r->json('data.redirect_url'));
        $this->assertSame(['failed', 'declined', 'pending'], [$r->json('data.payment_status'), $this->payment()->failure_code, $this->reservation()->status->value]);
    }

    public function test_when_tamara_cannot_be_reached_nothing_is_booked_or_charged(): void
    {
        $this->tamara->mode = 'down';

        $this->bookWith('tamara')->assertStatus(503)->assertJsonPath('code', 'PAYMENT_UNAVAILABLE');

        $this->assertSame(0, Payment::count());
        $this->assertSame('expired', $this->reservation()->status->value);
    }

    public function test_tamara_is_only_used_where_the_provider_accepts_it_and_in_a_currency_it_takes(): void
    {
        $this->a['activity']->update(['allows_tamara' => false]);
        $this->bookWith('tamara')->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->a['activity']->update(['allows_tamara' => true]);
        $this->enableTamara(['currencies' => ['AED']]);
        $this->bookWith('tamara')->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->assertCount(0, $this->tamara->calls('/checkout'));
    }

    // --- The customer pays: the notification, the check, authorise and capture --------------------------------------------------

    public function test_an_approval_is_checked_with_tamara_then_authorised_and_captured_and_the_booking_confirmed(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);

        $this->notify($id)->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertSame([PaymentStatus::Succeeded, 'confirmed'], [$this->payment()->status, $this->reservation()->status->value]);
        $this->assertCount(1, $this->tamara->calls("/orders/{$id}/authorise"));
        $capture = $this->tamara->calls('/payments/capture');
        $this->assertCount(1, $capture);
        $this->assertSame([$id, 3000.0, 'SAR'], [$capture[0]['body']['order_id'], (float) $capture[0]['body']['total_amount']['amount'], $capture[0]['body']['total_amount']['currency']]);
        $this->assertArrayHasKey('shipped_at', $capture[0]['body']['shipping_info']);
        $this->assertSame('fully_captured', $this->tamara->orders[$id]['status']);
        $this->assertSame('Bearer tamara-api-token', $capture[0]['auth']);
    }

    public function test_an_order_that_is_already_authorised_is_captured_without_authorising_again(): void
    {
        $id = $this->started();
        $this->tamara->orders[$id]['status'] = 'authorised';

        $this->notify($id, 'order_authorised')->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertCount(0, $this->tamara->calls("/orders/{$id}/authorise"));
        $this->assertCount(1, $this->tamara->calls('/payments/capture'));
    }

    public function test_the_token_may_come_as_the_tamaraToken_parameter(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);

        $this->notify($id, 'order_approved', null, true)->assertOk()->assertJsonPath('data.status', 'succeeded');
    }

    public function test_nothing_is_settled_on_what_the_notification_says_only_on_what_tamara_says(): void
    {
        $id = $this->started();   // the customer has NOT approved: the order is still new

        $this->notify($id, 'order_approved')->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertSame([PaymentStatus::Pending, 'pending'], [$this->payment()->status, $this->reservation()->status->value]);
        $this->assertCount(0, $this->tamara->calls('/authorise'));
        $this->assertCount(0, $this->tamara->calls('/payments/capture'));
    }

    public function test_the_same_notification_twice_settles_once(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);

        $this->notify($id)->assertOk();
        $this->notify($id)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->notify($id, 'order_captured')->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertCount(1, $this->tamara->calls('/payments/capture'));
        $this->assertCount(1, $this->tamara->calls("/orders/{$id}/authorise"));
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentEvent::where('event_key', "{$id}:order_approved")->count());
    }

    public function test_a_notification_that_does_not_prove_itself_is_refused_and_changes_nothing(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);
        $calls = count($this->tamara->calls);

        $this->notify($id, 'order_approved', '')->assertStatus(401);                                                                   // no token
        $this->notify($id, 'order_approved', $this->token([], 'some-other-secret'))->assertStatus(401);                                // signed with another secret
        $this->notify($id, 'order_approved', $this->token(['exp' => time() - 7200]))->assertStatus(401);                              // expired
        $this->notify($id, 'order_approved', 'garbage')->assertStatus(401);
        $this->notify($id, 'order_approved', rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=').'.'.rtrim(strtr(base64_encode('{"iss":"Tamara"}'), '+/', '-_'), '=').'.')->assertStatus(401);   // alg none

        $this->assertSame([PaymentStatus::Pending, 'pending'], [$this->payment()->status, $this->reservation()->status->value]);
        $this->assertCount($calls, $this->tamara->calls, 'Tamara was not even asked');
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_a_notification_for_an_order_we_do_not_know_is_refused(): void
    {
        $this->started();

        $this->postJson('/api/v1/webhooks/payments/tamara', ['order_id' => 'nobody', 'event_type' => 'order_approved'], ['Authorization' => 'Bearer '.$this->token()])->assertStatus(422);
        $this->postJson('/api/v1/webhooks/payments/tamara', ['event_type' => 'order_approved'], ['Authorization' => 'Bearer '.$this->token()])->assertStatus(401);
    }

    public function test_a_notification_for_a_gateway_that_is_not_switched_on_is_not_found(): void
    {
        config(['payments.gateways.tamara.enabled' => false]);

        $this->postJson('/api/v1/webhooks/payments/tamara', ['order_id' => 'x'], ['Authorization' => 'Bearer '.$this->token()])->assertStatus(404);
    }

    public function test_a_declined_order_fails_with_its_reason_and_the_customer_may_try_again(): void
    {
        $id = $this->started();
        $this->tamara->decline($id);

        $this->notify($id, 'order_declined')->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(['declined', 'pending'], [$this->payment()->failure_code, $this->reservation()->status->value]);
        $this->assertCount(0, $this->tamara->calls('/payments/capture'));
    }

    public function test_an_order_that_expired_or_was_cancelled_at_tamara_fails_with_that_reason(): void
    {
        $id = $this->started();
        $this->tamara->expire($id);
        $this->notify($id, 'order_expired')->assertOk();
        $this->assertSame('expired', $this->payment()->failure_code);

        $this->bookWith('tamara', 1, [], 'second-key')->assertCreated();   // a second booking, a second order
        $second = $this->tamara->id(2);
        $this->tamara->orders[$second]['status'] = 'canceled';
        $this->notify($second, 'order_canceled')->assertOk();
        $this->assertSame('cancelled', $this->payment()->failure_code);
    }

    // --- What Tamara says is checked against what we asked ----------------------------------------------------------------------

    public function test_an_amount_that_is_not_the_one_asked_for_is_never_authorised_or_captured(): void
    {
        $id = $this->started();
        $this->tamara->orders[$id]['total_amount']['amount'] = 1.0;
        $this->tamara->approve($id);

        $this->notify($id)->assertOk();

        $this->assertSame('mismatch', $this->payment()->failure_code);
        $this->assertCount(0, $this->tamara->calls('/authorise'));
        $this->assertCount(0, $this->tamara->calls('/payments/capture'));
    }

    public function test_a_currency_or_an_order_that_is_not_ours_is_refused(): void
    {
        $id = $this->started();
        $this->tamara->orders[$id]['total_amount']['currency'] = 'AED';
        $this->tamara->approve($id);
        $this->notify($id)->assertOk();
        $this->assertSame('mismatch', $this->payment()->failure_code);

        $this->bookWith('tamara', 1, [], 'second-key')->assertCreated();
        $second = $this->tamara->id(2);
        $this->tamara->orders[$second]['order_reference_id'] = 'SOMEONE-ELSES-ORDER';
        $this->tamara->approve($second);
        // (a different reference means the lookup by our reference finds nothing: Tamara answers 404, which is "could not tell")
        $this->expectException(PaymentGatewayException::class);
        app(PaymentService::class)->reconcile($this->payment());
    }

    // --- Too late ---------------------------------------------------------------------------------------------------------------------

    public function test_an_approval_after_the_hold_ran_out_is_not_authorised_so_nobody_is_charged(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);
        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $this->notify($id)->assertOk();

        $this->assertSame(['late_payment', PaymentStatus::Failed], [$this->payment()->failure_code, $this->payment()->status]);
        $this->assertCount(0, $this->tamara->calls('/authorise'), 'Never authorised, so Tamara takes nothing and the order lapses by itself');
        $this->assertCount(0, $this->tamara->calls('/payments/capture'));
        $this->assertNotSame('confirmed', $this->reservation()->fresh()->status->value);
    }

    public function test_an_authorised_order_for_a_booking_that_lapsed_is_cancelled_at_tamara(): void
    {
        $id = $this->started();
        $this->tamara->orders[$id]['status'] = 'authorised';
        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $this->notify($id, 'order_authorised')->assertOk();

        $this->assertSame('canceled', $this->tamara->orders[$id]['status']);
        $this->assertCount(0, $this->tamara->calls('/payments/capture'));
        $this->assertSame('late_payment', $this->payment()->failure_code);
    }

    public function test_an_approval_for_a_booking_the_customer_cancelled_is_not_taken(): void
    {
        $id = $this->started();
        $this->ca($this->token, 'POST', "/bookings/{$this->reservation()->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->tamara->approve($id);

        $this->notify($id)->assertOk();

        $this->assertSame('cancelled', $this->reservation()->fresh()->status->value);
        $this->assertCount(0, $this->tamara->calls('/payments/capture'));
        $this->assertSame(PaymentStatus::Failed, $this->payment()->status);
    }

    public function test_cancelling_or_letting_the_hold_run_out_closes_the_open_checkout(): void
    {
        $this->started();
        $this->ca($this->token, 'POST', "/bookings/{$this->reservation()->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->assertSame(['failed', 'cancelled'], [$this->payment()->status->value, $this->payment()->failure_code]);

        $this->bookWith('tamara', 1, [], 'second-key')->assertCreated();
        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->artisan('reservations:expire-stale-holds')->assertSuccessful();
        $this->assertSame(['failed', 'expired'], [$this->payment()->status->value, $this->payment()->failure_code]);
    }

    public function test_money_taken_by_tamara_for_a_booking_that_lapsed_is_given_back(): void
    {
        $id = $this->started();
        $this->tamara->orders[$id]['status'] = 'fully_captured';
        $this->tamara->orders[$id]['captured'] = 3000;
        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $this->notify($id, 'order_captured')->assertOk();

        $this->assertSame([PaymentStatus::Refunded, 'late_payment'], [$this->payment()->status, $this->payment()->failure_code]);
        $this->assertEquals(3000, $this->payment()->refunded_amount);
        $this->assertEquals(3000.0, (float) $this->tamara->calls("/payments/simplified-refund/{$id}")[0]['body']['total_amount']['amount']);
    }

    // --- Recovery ---------------------------------------------------------------------------------------------------------------

    public function test_when_the_capture_cannot_be_made_the_booking_is_not_confirmed_and_it_is_tried_again(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);
        $this->tamara->mode = 'capture_down';

        $this->notify($id)->assertStatus(502);
        $this->assertSame([PaymentStatus::Pending, 'pending'], [$this->payment()->status, $this->reservation()->status->value]);
        $this->assertSame(0, PaymentEvent::whereNotNull('result')->count());

        $this->tamara->mode = 'ok';
        $this->notify($id)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('confirmed', $this->reservation()->status->value);
        $this->assertCount(1, $this->tamara->calls("/orders/{$id}/authorise"), 'It was authorised once, by the first try: the second only captured');
        $this->assertSame('fully_captured', $this->tamara->orders[$id]['status']);
    }

    public function test_the_reconciler_settles_an_order_whose_notification_never_came_and_leaves_the_rest(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);

        $this->artisan('payments:reconcile')->assertSuccessful();   // too young
        $this->assertSame(PaymentStatus::Pending, $this->payment()->status);

        Carbon::setTestNow(Carbon::now()->addMinutes(3));
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame([PaymentStatus::Succeeded, 'confirmed'], [$this->payment()->status, $this->reservation()->status->value]);
    }

    public function test_the_reconciler_goes_on_when_tamara_cannot_be_reached(): void
    {
        $this->started();
        Carbon::setTestNow(Carbon::now()->addMinutes(3));
        $this->tamara->mode = 'down';

        $this->artisan('payments:reconcile')->expectsOutputToContain('1 could not be checked')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $this->payment()->status);
    }

    // --- The customer comes back ---------------------------------------------------------------------------------------------------

    private function returnTo(string $outcome, array $query = [], bool $signed = true): string
    {
        $url = URL::temporarySignedRoute('payments.return', now()->addDay(), ['payment' => $this->payment()->uuid, 'outcome' => $outcome]);

        return $signed ? $url.($query ? '&'.http_build_query($query) : '') : "/api/v1/payments/{$this->payment()->uuid}/return/{$outcome}";
    }

    public function test_coming_back_after_approving_settles_it_even_before_the_notification(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);

        // Tamara adds its own parameters to the address; they do not break the signature.
        $this->getJson($this->returnTo('success', ['orderId' => $id, 'paymentStatus' => 'approved']))->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame('confirmed', $this->reservation()->status->value);
        $this->notify($id)->assertOk();
        $this->assertCount(1, $this->tamara->calls('/payments/capture'));
    }

    public function test_the_customer_is_sent_on_to_the_app_with_the_outcome(): void
    {
        config(['payments.return_urls.success' => 'marinebooking://paid', 'payments.return_urls.cancel' => 'marinebooking://cancelled', 'payments.return_urls.failure' => 'marinebooking://failed']);
        $this->started();

        $r = $this->get($this->returnTo('cancel'));

        $this->assertStringStartsWith('marinebooking://cancelled?', $r->headers->get('Location'));
        parse_str((string) parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame([$this->reservation()->uuid, 'cancelled'], [$q['booking'], $q['status']]);
        $this->assertSame(['failed', 'cancelled', 'pending'], [$this->payment()->status->value, $this->payment()->failure_code, $this->reservation()->status->value]);
    }

    public function test_the_return_trusts_tamara_over_the_customer_and_refuses_an_address_that_was_changed(): void
    {
        $id = $this->started();
        $this->tamara->approve($id);

        $this->getJson($this->returnTo('success', [], false))->assertStatus(403);
        $this->getJson(str_replace('/return/success', '/return/failure', $this->returnTo('success')))->assertStatus(403);
        $this->assertSame(PaymentStatus::Pending, $this->payment()->status);

        $this->getJson($this->returnTo('failure'))->assertOk()->assertJsonPath('data.status', 'paid');   // "failure", but Tamara has the approval
    }

    // --- Duplicates and retries -------------------------------------------------------------------------------------------------------

    public function test_asking_to_pay_again_gives_the_same_checkout_and_a_paid_booking_cannot_be_paid_again(): void
    {
        $this->started();

        $this->payAgain($this->reservation(), 'tamara')->assertCreated()->assertJsonPath('data.redirect_url', 'https://checkout.tamara.test/checkout/chk-1');
        $this->assertCount(1, $this->tamara->calls('/checkout'));
        $this->assertSame(1, Payment::count());

        $this->tamara->approve($this->tamara->id());
        $this->notify($this->tamara->id())->assertOk();
        $this->payAgain($this->reservation(), 'tamara')->assertStatus(422);
        $this->assertSame(1, Payment::count());
    }

    public function test_after_a_decline_the_customer_can_try_again_and_pay(): void
    {
        $id = $this->started();
        $this->tamara->decline($id);
        $this->notify($id, 'order_declined')->assertOk();

        $this->payAgain($this->reservation(), 'tamara')->assertCreated()->assertJsonPath('data.redirect_url', 'https://checkout.tamara.test/checkout/chk-2');

        $this->tamara->approve($this->tamara->id(2));
        $this->notify($this->tamara->id(2))->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('confirmed', $this->reservation()->status->value);
    }

    public function test_asking_for_another_method_closes_the_open_tamara_checkout_so_it_cannot_also_be_charged(): void
    {
        $id = $this->started();

        $this->payAgain($this->reservation(), 'card')->assertCreated();

        $first = Payment::orderBy('id')->first();
        $this->assertSame(['tamara', 'failed', 'cancelled'], [$first->gateway, $first->status->value, $first->failure_code]);
        $this->tamara->approve($id);
        $this->notify($id)->assertOk();
        $this->assertCount(0, $this->tamara->calls('/payments/capture'), 'The abandoned checkout is not charged');
    }

    // --- Refunds ----------------------------------------------------------------------------------------------------------------

    public function test_a_payment_is_refunded_through_tamara_in_full_or_in_part(): void
    {
        $id = $this->paid();

        $part = app(PaymentService::class)->refund($this->payment(), 500);
        $this->assertSame([PaymentStatus::PartiallyRefunded, 500.0], [$part->status, (float) $part->refunded_amount]);
        $rest = app(PaymentService::class)->refund($part);
        $this->assertSame([PaymentStatus::Refunded, 3000.0], [$rest->status, (float) $rest->refunded_amount]);

        $refunds = $this->tamara->calls("/payments/simplified-refund/{$id}");
        $this->assertEquals([500.0, 2500.0], array_map(fn ($c) => (float) $c['body']['total_amount']['amount'], $refunds));
        $this->assertSame(3000.0, (float) $this->tamara->orders[$id]['refunded']);
    }

    public function test_a_refund_tamara_refuses_changes_nothing_and_says_so(): void
    {
        $id = $this->paid();
        $this->tamara->orders[$id]['captured'] = 0;   // Tamara holds no capture for it

        try {
            app(PaymentService::class)->refund($this->payment(), 100);
            $this->fail('The refund should have been refused.');
        } catch (PaymentGatewayException $e) {
            $this->assertStringContainsString('tamara refused the refund', $e->getMessage());
        }

        $this->assertSame([PaymentStatus::Succeeded, 0.0], [$this->payment()->status, (float) $this->payment()->refunded_amount]);
    }

    public function test_cancelling_a_paid_booking_refunds_the_customer_through_tamara(): void
    {
        $id = $this->paid();

        $this->ca($this->token, 'POST', "/bookings/{$this->reservation()->id}/cancel", ['reason' => 'Plans changed'])->assertOk();

        $this->assertNotEmpty($this->tamara->calls("/payments/simplified-refund/{$id}"));
        $this->assertContains($this->payment()->fresh()->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded]);
        $this->assertGreaterThan(0, $this->tamara->orders[$id]['refunded']);
    }

    // --- Readiness, and what is never logged ------------------------------------------------------------------------------------------

    public function test_a_gateway_that_is_enabled_but_not_fully_set_up_is_refused_not_half_used(): void
    {
        $this->enableTamara(['notification_token' => '']);

        $this->bookWith('tamara')->assertStatus(503)->assertJsonPath('code', 'PAYMENT_UNAVAILABLE');

        $this->assertCount(0, $this->tamara->calls);
    }

    public function test_in_production_tamara_that_is_not_switched_on_is_refused_and_never_falls_back_to_the_mock(): void
    {
        config(['payments.gateways.tamara.enabled' => false]);
        $this->app['env'] = 'production';

        $this->bookWith('tamara')->assertStatus(503)->assertJsonPath('code', 'PAYMENT_UNAVAILABLE');

        $this->assertSame(0, Payment::count());
    }

    public function test_the_sandbox_and_the_production_hosts_are_different_and_the_url_can_be_overridden(): void
    {
        $this->enableTamara(['base_url' => null, 'environment' => 'sandbox']);
        $this->bookWith('tamara')->assertCreated();
        $this->assertStringContainsString('api-sandbox.tamara.co', \Illuminate\Support\Facades\Http::recorded()[0][0]->url());

        $this->enableTamara(['base_url' => null, 'environment' => 'production']);
        $this->bookWith('tamara', 1, [], 'second-key')->assertCreated();
        $hosts = array_map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_HOST), \Illuminate\Support\Facades\Http::recorded()->all());
        $this->assertContains('api.tamara.co', $hosts);
    }

    public function test_no_token_secret_or_customer_detail_is_ever_logged(): void
    {
        $logged = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info', 'warning', 'error', 'critical', 'debug')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = $message.' '.json_encode($context);
        });

        $id = $this->started();
        $this->tamara->orders[$id]['total_amount']['amount'] = 1.0;
        $this->tamara->approve($id);
        $this->notify($id);
        $this->notify($id, 'order_approved', $this->token([], 'wrong-secret'));

        $all = implode("\n", $logged);
        $this->assertNotEmpty($logged);
        foreach (['tamara-api-token', self::SECRET, 'wrong-secret', 'ahmed@example.test', '0501234567', '+966501234567', 'Ahmed Al-Harbi'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }
}
