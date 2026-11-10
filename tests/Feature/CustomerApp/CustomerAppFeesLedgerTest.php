<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\WalletEntry;
use App\Services\PaymentService;
use App\Services\ProviderApp\ProviderRevenue;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * The service fee is the platform's and the VAT the tax authority's: a provider earns the price after their own coupon, never more, and a refund of
 * part of what the customer paid takes back the same part of the provider's share. (The provider's commission here is 10%.)
 */
class CustomerAppFeesLedgerTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        config(['customer_app.service_fee_percent' => 3, 'customer_app.vat_percent' => 15]);
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
        [, , $this->token] = $this->signedInCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Books and pays through the app, then the trip is done. */
    private function completedBooking(array $over = []): Reservation
    {
        $id = $this->ca($this->token, 'POST', '/bookings', $over + ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1, 'contact_name' => 'Ahmed',
            'contact_phone' => '+966501234567', 'payment_method' => 'card'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.booking_id');
        $reservation = Reservation::find($id);
        $reservation->forceFill(['status' => 'completed'])->save();

        app(LedgerService::class)->syncBooking($reservation->refresh());

        return $reservation;
    }

    private function entries(Reservation $r): array
    {
        return WalletEntry::where('reservation_id', $r->id)->orderBy('id')->get()->map(fn ($e) => [$e->type, round($e->amount, 2)])->all();
    }

    private function earned(): float
    {
        return app(LedgerService::class)->balances($this->a['provider']->refresh())['total_earnings'];
    }

    public function test_the_provider_is_paid_the_price_and_not_the_fee_or_the_vat(): void
    {
        $r = $this->completedBooking();

        $this->assertEqualsWithDelta(1776.75, $r->total, 0.001, 'The customer paid the price, the fee and the VAT');
        $this->assertSame([['booking_income', 1500.0], ['platform_fee', -150.0]], $this->entries($r), 'The provider is credited the 1,500 and pays 10% commission on it');
        $this->assertEqualsWithDelta(1350, $this->earned(), 0.001);
    }

    public function test_the_income_entry_remembers_what_the_booking_was_made_of(): void
    {
        $r = $this->completedBooking();

        $meta = WalletEntry::where('reservation_id', $r->id)->where('type', 'booking_income')->firstOrFail()->meta;

        $this->assertEquals([1500, 0, 45, 231.75], [$meta['gross'], $meta['discount'], $meta['service_fee'], $meta['vat']]);
    }

    public function test_a_coupon_is_the_providers_gift_so_they_are_paid_the_price_after_it(): void
    {
        Coupon::create(['provider_id' => $this->a['provider']->id, 'code' => 'HALF', 'type' => 'percent', 'value' => 50, 'is_active' => true]);

        $r = $this->completedBooking(['coupon_code' => 'HALF']);

        // The customer paid 750 (after the coupon) + a fee of 45 (3% of the 1,500 list price) + VAT of 119.25 (15% of 750 + 45) = 914.25; the provider is owed the 750.
        $this->assertEqualsWithDelta(914.25, $r->total, 0.001);
        $this->assertSame([['booking_income', 750.0], ['platform_fee', -75.0]], $this->entries($r));
        $this->assertEqualsWithDelta(675, $this->earned(), 0.001);
    }

    public function test_a_refund_of_part_of_what_was_paid_takes_back_the_same_part_of_the_providers_share(): void
    {
        $r = $this->completedBooking();
        $payment = Payment::where('reservation_id', $r->id)->firstOrFail();

        app(PaymentService::class)->refund($payment, 888.38);   // about half of the 1,776.75 paid

        // Half the customer's money back means half the provider's 1,500 back (750), and the commission is on the 750 they keep.
        $this->assertSame([['booking_income', 1500.0], ['platform_fee', -150.0], ['refund', -750.0], ['platform_fee', 75.0]], $this->entries($r));
        $this->assertEqualsWithDelta(675, $this->earned(), 0.001);
    }

    public function test_a_full_refund_takes_back_all_of_it_and_nothing_is_left_over(): void
    {
        $r = $this->completedBooking();
        $payment = Payment::where('reservation_id', $r->id)->firstOrFail();

        app(PaymentService::class)->refund($payment, 1776.75);

        $this->assertEqualsWithDelta(0, $this->earned(), 0.001);
        $this->assertEqualsWithDelta(0, WalletEntry::where('reservation_id', $r->id)->sum('amount'), 0.001);
    }

    public function test_syncing_again_adds_nothing(): void
    {
        $r = $this->completedBooking();
        $before = $this->entries($r);

        app(LedgerService::class)->syncBooking($r->refresh());
        app(LedgerService::class)->syncBooking($r->refresh());

        $this->assertSame($before, $this->entries($r));
    }

    public function test_the_providers_analytics_show_the_price_not_what_the_customer_paid(): void
    {
        $this->completedBooking();

        $top = $this->hit($this->a['token'], 'GET', '/api/v1/provider/analytics?from=2026-11-01&to=2026-11-30')->assertOk()->json('data.top_activities.0');

        $this->assertEqualsWithDelta([1500, 1350], [$top['gross_revenue'], $top['net_revenue']], 0.001);
    }

    public function test_with_no_fee_and_no_vat_nothing_changes_from_before(): void
    {
        config(['customer_app.service_fee_percent' => 0, 'customer_app.vat_percent' => 0]);

        $r = $this->completedBooking();

        $this->assertEqualsWithDelta(1500, $r->total, 0.001);
        $this->assertSame([['booking_income', 1500.0], ['platform_fee', -150.0]], $this->entries($r));
    }

    public function test_the_providers_booking_list_and_customer_list_show_the_price_not_the_customers_total(): void
    {
        $this->completedBooking();

        $booking = $this->hit($this->a['token'], 'GET', '/api/v1/provider/bookings')->assertOk()->json('data.bookings.0');
        $customer = $this->hit($this->a['token'], 'GET', '/api/v1/provider/customers')->assertOk()->json('data.customers.0');

        $this->assertEqualsWithDelta(1500, $booking['amount'], 0.001, 'Not the 1,776.75 the customer paid');
        $this->assertEqualsWithDelta(1500, $customer['total_spent'], 0.001);
    }

    public function test_what_the_provider_can_expect_from_a_paid_booking_that_has_not_happened_yet_is_worked_out_on_the_price(): void
    {
        $id = $this->ca($this->token, 'POST', '/bookings', ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1, 'contact_name' => 'Ahmed',
            'contact_phone' => '+966501234567', 'payment_method' => 'card'], ['Idempotency-Key' => 'k1'])->assertCreated()->json('data.booking_id');

        $this->assertEqualsWithDelta(1350, app(ProviderRevenue::class)->expectedFromConfirmed($this->a['provider']), 0.001, '1,500 less 10% commission');

        app(PaymentService::class)->refund(Payment::where('reservation_id', $id)->firstOrFail(), 888.38);   // about half

        $this->assertEqualsWithDelta(675, app(ProviderRevenue::class)->expectedFromConfirmed($this->a['provider']), 0.001, 'Half of the price is still the provider\'s, less commission');
    }

    public function test_the_providers_amount_of_a_booking_is_the_total_less_the_fee_and_the_vat(): void
    {
        $r = new Reservation(['total' => 1776.75, 'service_fee' => 45, 'tax' => 231.75]);

        $this->assertEqualsWithDelta(1500, $r->providerAmount(), 0.001);
        $this->assertEqualsWithDelta(1500, (new Reservation(['total' => 1500]))->providerAmount(), 0.001);
    }
}
