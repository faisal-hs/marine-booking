<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsOnlinePayments;
use Tests\Support\FakeTabby;
use Tests\TestCase;

/**
 * What the customer sees after the gateway sends them back: a PAGE with the status, the amount and the reference numbers (in English or Arabic); JSON for a client that asks for it; and
 * the configured redirect to the app, as before. The payment itself is settled as PaymentReturnController says (see TabbyPaymentTest for that).
 */
class PaymentReturnPageTest extends TestCase
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

    private function started(): string
    {
        $this->bookWith('tabby')->assertCreated();

        return $this->tabby->id();
    }

    private function returnUrl(string $outcome = 'success', array $extra = []): string
    {
        return URL::temporarySignedRoute('payments.return', now()->addDay(), ['payment' => $this->payment()->uuid, 'outcome' => $outcome] + $extra);
    }

    /** What a customer's browser does: ask for a page. */
    private function page(string $url, array $headers = [])
    {
        return $this->get($url, ['Accept' => 'text/html,application/xhtml+xml'] + $headers);
    }

    private function bookingReference(): string
    {
        $r = $this->reservation();

        return 'BH-'.$r->reservation_date->format('ymd').'-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT);
    }

    // --- The page, for each status -----------------------------------------------------------------------------------------------------

    public function test_after_paying_the_customer_sees_the_status_the_amount_and_the_reference_numbers(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);

        $r = $this->page($this->returnUrl())->assertOk();

        $this->assertStringContainsString('text/html', $r->headers->get('Content-Type'));
        $r->assertSee('Payment received')->assertSee('Amount paid')->assertSee('3,000.00')->assertSee('SAR')
            ->assertSee($this->payment()->reference_id)->assertSee($this->bookingReference())->assertSee('Reef Dive')->assertSee('2026-11-21')->assertSee('Tabby')->assertSee('Paid on');
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('http-equiv="refresh"', $r->getContent(), 'A settled payment does not refresh');
        $this->assertSame('confirmed', $this->reservation()->status->value);
    }

    public function test_the_reference_numbers_are_the_ones_the_booking_and_the_payment_carry(): void
    {
        $this->started();

        $content = $this->page($this->returnUrl())->getContent();

        $this->assertMatchesRegularExpression('/PAY-[A-Z0-9]{10}/', $content);
        $this->assertStringContainsString($this->payment()->reference_id, $content);
        $this->assertStringContainsString($this->bookingReference(), $content);
        $this->assertMatchesRegularExpression('/BH-261121-\d{4}/', $content);
    }

    public function test_a_payment_still_being_confirmed_says_so_and_refreshes_by_itself(): void
    {
        $this->started();   // the customer has not approved yet

        $r = $this->page($this->returnUrl())->assertOk();

        $r->assertSee('We are confirming your payment')->assertSee('3,000.00')->assertDontSee('Amount paid')->assertSee('Checking again in a few seconds');
        $this->assertStringContainsString('<meta http-equiv="refresh" content="8">', $r->getContent());
    }

    public function test_a_declined_payment_says_why_and_that_nothing_was_taken(): void
    {
        $id = $this->started();
        $this->tabby->reject($id);

        $this->page($this->returnUrl('failure'))->assertOk()->assertSee('The payment was not completed')->assertSee('The payment provider did not approve the payment.')->assertSee('Nothing was taken from you')
            ->assertSee($this->payment()->reference_id)->assertDontSee('Amount paid');
    }

    public function test_a_cancelled_payment_says_so(): void
    {
        $this->started();

        $this->page($this->returnUrl('cancel'))->assertOk()->assertSee('Payment cancelled')->assertSee('You cancelled the payment');
        $this->assertSame('cancelled', $this->payment()->failure_code);
    }

    public function test_an_expired_payment_says_so(): void
    {
        $id = $this->started();
        $this->tabby->expire($id);

        $this->page($this->returnUrl('failure'))->assertOk()->assertSee('The payment time ran out')->assertSee('Please book again');
    }

    public function test_a_payment_that_arrived_too_late_is_shown_as_returned_with_the_amount_refunded(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);
        Carbon::setTestNow(Carbon::now()->addMinutes(30));   // the hold ran out before the customer approved: the approval is voided

        $this->page($this->returnUrl())->assertOk()->assertSee('The payment was not completed')->assertSee('arrived after the booking had expired');

        // Where Tabby had already taken the money, it is given back and the page says how much.
        $this->tabby->payments[$id]['captures'][] = ['id' => 'c1', 'amount' => '3000.00'];
        $this->tabby->payments[$id]['status'] = 'CLOSED';
        Payment::query()->update(['status' => 'pending', 'failure_code' => null]);
        $this->page($this->returnUrl('success', ['x' => 1]))->assertOk()->assertSee('Refunded')->assertSee('3,000.00');
    }

    public function test_when_the_gateway_cannot_be_reached_the_page_says_it_is_still_being_confirmed(): void
    {
        $this->started();
        $this->tabby->mode = 'down';

        $this->page($this->returnUrl())->assertOk()->assertSee('We are confirming your payment');
    }

    // --- Language ----------------------------------------------------------------------------------------------------------------------

    public function test_the_page_speaks_arabic_when_the_address_says_so(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);

        $r = $this->page($this->returnUrl('success', ['lang' => 'ar']))->assertOk();

        $r->assertSee('dir="rtl"', false)->assertSee('lang="ar"', false)->assertSee('تم استلام الدفع')->assertSee('المبلغ المدفوع')->assertSee('رقم مرجع الدفع')->assertSee('رقم مرجع الحجز')
            ->assertSee($this->payment()->reference_id)->assertSee('3,000.00')->assertDontSee('Payment received');
    }

    public function test_it_follows_the_browsers_language_when_the_address_does_not_say(): void
    {
        $this->started();

        $this->page($this->returnUrl(), ['Accept-Language' => 'ar-SA,ar;q=0.9,en;q=0.5'])->assertOk()->assertSee('dir="rtl"', false);
        $this->page($this->returnUrl(), ['Accept-Language' => 'en-US'])->assertOk()->assertSee('dir="ltr"', false);
        $this->page($this->returnUrl(), ['Accept-Language' => 'fr'])->assertOk()->assertSee('dir="ltr"', false);
    }

    public function test_the_apps_language_is_signed_into_the_address_the_gateway_is_given(): void
    {
        $this->ca($this->token, 'POST', '/bookings', ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 2, 'contact_name' => 'Ahmed Al-Harbi', 'contact_phone' => '0501234567',
            'payment_method' => 'tabby'], ['Idempotency-Key' => 'ar-key', 'Accept-Language' => 'ar'])->assertCreated();

        $url = $this->tabby->calls('/api/v2/checkout')[0]['body']['merchant_urls']['success'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('ar', $q['lang']);
        $this->page($url)->assertOk()->assertSee('dir="rtl"', false);
        $this->page(preg_replace('/lang=ar/', 'lang=en', $url))->assertStatus(403);   // changing it breaks the signature
    }

    // --- JSON, redirect, the button -----------------------------------------------------------------------------------------------------

    public function test_a_client_that_asks_for_json_gets_the_data_with_the_amount_and_the_references(): void
    {
        $id = $this->started();
        $this->tabby->approve($id);

        $r = $this->getJson($this->returnUrl())->assertOk();

        $this->assertSame(['success', 'message', 'data'], array_keys($r->json()));
        $this->assertSame('Request completed successfully.', $r->json('message'));
        $this->assertSame(['booking', 'payment', 'status', 'reason', 'amount', 'currency', 'booking_reference', 'paid_at'], array_keys($r->json('data')));
        $this->assertEquals([$this->reservation()->uuid, $this->payment()->reference_id, 'paid', null, 3000, 'SAR', $this->bookingReference()], array_slice(array_values($r->json('data')), 0, 7));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r->json('data.paid_at'));
    }

    public function test_json_can_also_be_asked_for_in_the_address(): void
    {
        $this->started();

        $r = $this->page($this->returnUrl('success', ['format' => 'json']))->assertOk();

        $this->assertSame('pending', $r->json('data.status'));
        $this->assertNull($r->json('data.paid_at'));
        $this->assertStringContainsString('application/json', $r->headers->get('Content-Type'));
    }

    public function test_a_failed_payment_in_json_carries_the_reason(): void
    {
        $id = $this->started();
        $this->tabby->reject($id);

        $d = $this->getJson($this->returnUrl('failure'))->assertOk()->json('data');

        $this->assertSame(['failed', 'The gateway declined the payment.'], [$d['status'], $d['reason']]);
    }

    public function test_with_an_address_for_the_app_the_customer_is_sent_there_and_not_shown_the_page(): void
    {
        config(['payments.return_urls.success' => 'marinebooking://paid']);
        $id = $this->started();
        $this->tabby->approve($id);

        $r = $this->page($this->returnUrl());

        $this->assertStringStartsWith('marinebooking://paid?', $r->headers->get('Location'));
        $r->assertDontSee('Payment received');
    }

    public function test_the_page_has_a_back_to_the_app_button_only_when_there_is_somewhere_to_go(): void
    {
        $this->started();

        $this->page($this->returnUrl())->assertDontSee('Back to the app');

        config(['payments.return_urls.app' => 'marinebooking://home']);
        $this->page($this->returnUrl())->assertSee('Back to the app')->assertSee('href="marinebooking://home"', false);
    }

    // --- Safety -----------------------------------------------------------------------------------------------------------------------

    public function test_whatever_is_written_in_a_title_cannot_run_in_the_page(): void
    {
        $this->a['activity']->update(['title' => '<script>alert("x")</script>']);
        $this->started();

        $content = $this->page($this->returnUrl())->getContent();

        $this->assertStringNotContainsString('<script>alert', $content);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $content);
    }

    public function test_an_address_that_is_not_signed_shows_no_page(): void
    {
        $this->started();

        $this->page("/api/v1/payments/{$this->payment()->uuid}/return/success")->assertStatus(403);
        $this->assertStringNotContainsString($this->payment()->reference_id, (string) $this->page("/api/v1/payments/{$this->payment()->uuid}/return/success")->getContent());
    }
}
