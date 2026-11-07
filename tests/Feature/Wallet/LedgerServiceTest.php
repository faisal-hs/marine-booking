<?php

namespace Tests\Feature\Wallet;

use App\Events\PaymentRefunded;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\WalletEntry;
use App\Services\ReservationService;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The wallet ledger. Today is Sunday 15 November 2026. A completed booking is posted to its provider's ledger exactly once: its income, any refund and
 * the platform's commission under the provider's own terms; and its money can be withdrawn only after the waiting period (config/wallet.php).
 */
class LedgerServiceTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->ledger = app(LedgerService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function entries(int $reservationId): array
    {
        return WalletEntry::where('reservation_id', $reservationId)->orderBy('id')->get()->map(fn ($e) => [$e->type, $e->amount])->all();
    }

    private function net(int $reservationId): float
    {
        return round((float) WalletEntry::where('reservation_id', $reservationId)->sum('amount'), 2);
    }

    // --- What is posted ---------------------------------------------------------------------------------------------

    public function test_a_completed_booking_posts_its_income_and_the_platform_commission(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 1000);

        $this->assertSame([['booking_income', 1000.0], ['platform_fee', -100.0]], $this->entries($id));
        $this->assertSame(900.0, $this->ledger->balances($a['provider'])['available']);
    }

    public function test_only_a_completed_booking_is_money_earned(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $customer = $this->customerNamed('Fatima');

        foreach (['pending', 'confirmed', 'cancelled', 'expired'] as $status) {
            $id = $this->makeBooking($a, $customer, '2026-11-10', $status, 500);
            $this->ledger->syncBooking(Reservation::find($id));
            $this->assertSame([], $this->entries($id), "A {$status} booking earns nothing yet");
        }
    }

    public function test_posting_twice_changes_nothing(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 1000);

        $this->ledger->syncBooking(Reservation::find($id));
        $this->ledger->syncBooking(Reservation::find($id));
        $this->ledger->syncProvider($a['provider']);

        $this->assertCount(2, $this->entries($id));
        $this->assertSame(1, WalletEntry::where('reference', "booking:{$id}:income")->count());
    }

    public function test_a_provider_with_no_commission_gets_only_the_income(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 0]);
        $id = $this->fundWallet($a, 750);

        $this->assertSame([['booking_income', 750.0]], $this->entries($id));
    }

    // --- Refunds -------------------------------------------------------------------------------------------------

    public function test_a_refund_made_before_posting_reduces_the_money_and_the_commission(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 1000, refunded: 200);

        $this->assertSame([['booking_income', 1000.0], ['refund', -200.0], ['platform_fee', -80.0]], $this->entries($id), 'Commission is on what the provider kept: 800');
        $this->assertSame(720.0, $this->net($id));
    }

    public function test_a_refund_made_after_posting_is_a_correction_not_a_rewrite(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 1000);
        $payment = Payment::where('reservation_id', $id)->first();

        $payment->update(['refunded_amount' => 300, 'status' => 'partially_refunded']);
        $this->ledger->syncBooking(Reservation::find($id));

        $this->assertSame([['booking_income', 1000.0], ['platform_fee', -100.0], ['refund', -300.0], ['platform_fee', 30.0]], $this->entries($id), 'The original rows stay; new ones correct them');
        $this->assertSame(630.0, $this->net($id));

        $this->ledger->syncBooking(Reservation::find($id));
        $this->assertCount(4, $this->entries($id), 'Nothing more to correct');

        $payment->update(['refunded_amount' => 500]);
        $this->ledger->syncBooking(Reservation::find($id));
        $this->assertSame(450.0, $this->net($id), '1000 - 500 refunded - 10% of the 500 kept');
    }

    public function test_the_refund_event_updates_the_ledger(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 1000);
        $payment = Payment::where('reservation_id', $id)->first();
        $payment->update(['refunded_amount' => 1000, 'status' => 'refunded']);

        event(new PaymentRefunded($payment->refresh()));

        $this->assertSame(0.0, $this->net($id), 'Refunded in full: nothing earned, no commission either');
    }

    public function test_a_refund_never_takes_more_than_the_booking_paid(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 100, refunded: 250);

        $this->assertSame(0.0, $this->net($id));
    }

    // --- Commission terms ---------------------------------------------------------------------------------------

    public function test_a_fixed_commission_is_one_amount_per_booking_and_never_more_than_the_booking(): void
    {
        $a = $this->approvedProvider(['commission_type' => 'fixed', 'commission_rate' => 25]);

        $this->assertSame([['booking_income', 1000.0], ['platform_fee', -25.0]], $this->entries($this->fundWallet($a, 1000)));
        $this->assertSame([['booking_income', 20.0], ['platform_fee', -20.0]], $this->entries($this->fundWallet($a, 20)));
    }

    public function test_a_fixed_commission_is_given_back_when_the_booking_is_refunded_in_full(): void
    {
        $a = $this->approvedProvider(['commission_type' => 'fixed', 'commission_rate' => 25]);
        $id = $this->fundWallet($a, 1000);
        Payment::where('reservation_id', $id)->update(['refunded_amount' => 1000]);

        $this->ledger->syncBooking(Reservation::find($id));

        $this->assertSame(0.0, $this->net($id));
    }

    public function test_later_changes_to_the_providers_terms_never_rewrite_a_posted_booking(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 1000);

        // The admin raises the commission to 50% and then the booking is part-refunded: the booking is still worked out at the 10% it was posted at.
        $a['provider']->update(['commission_rate' => 50]);
        Payment::where('reservation_id', $id)->update(['refunded_amount' => 100]);
        $this->ledger->syncBooking(Reservation::find($id));

        $this->assertSame(810.0, $this->net($id), '1000 - 100 refunded - 10% of 900');
        $other = $this->fundWallet($a, 1000);
        $this->assertSame(500.0, $this->net($other), 'A booking posted after the change uses the new terms');
    }

    // --- The waiting period -----------------------------------------------------------------------------------

    public function test_with_no_waiting_period_the_money_is_available_once_the_trip_day_is_over(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $this->fundWallet($a, 1000, '2026-11-14');

        $this->assertSame(['available' => 900.0, 'pending' => 0.0], array_intersect_key($this->ledger->balances($a['provider']), ['available' => 1, 'pending' => 1]));
    }

    public function test_the_waiting_period_comes_from_the_configuration_and_counts_from_the_end_of_the_trip_day(): void
    {
        config(['wallet.settlement_delay_days' => 3]);
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 1000, '2026-11-13');   // the trip was Friday

        $this->assertSame('2026-11-16 23:59:59', WalletEntry::where('reservation_id', $id)->first()->available_at->toDateTimeString());
        $now = $this->ledger->balances($a['provider']);
        $this->assertSame([0.0, 900.0], [$now['available'], $now['pending']], 'Still waiting');

        $later = $this->ledger->balances($a['provider'], Carbon::parse('2026-11-17 00:00:00'));
        $this->assertSame([900.0, 0.0], [$later['available'], $later['pending']], 'Available after the period');
    }

    public function test_a_refund_inside_the_waiting_period_comes_off_the_pending_money(): void
    {
        config(['wallet.settlement_delay_days' => 3]);
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->fundWallet($a, 1000, '2026-11-13');
        Payment::where('reservation_id', $id)->update(['refunded_amount' => 500]);
        $this->ledger->syncBooking(Reservation::find($id));

        $this->assertSame(450.0, $this->ledger->balances($a['provider'])['pending']);
        $this->assertSame(0.0, $this->ledger->balances($a['provider'])['available']);
    }

    public function test_the_five_balances(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $this->fundWallet($a, 1000);
        DB::table('withdrawals')->insert([
            ['uuid' => 'w1', 'provider_id' => $a['provider']->id, 'amount' => 200, 'fee' => 0, 'net_amount' => 200, 'currency' => 'SAR', 'status' => 'pending', 'idempotency_key' => 'k1', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => 'w2', 'provider_id' => $a['provider']->id, 'amount' => 100, 'fee' => 0, 'net_amount' => 100, 'currency' => 'SAR', 'status' => 'paid', 'idempotency_key' => 'k2', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => 'w3', 'provider_id' => $a['provider']->id, 'amount' => 50, 'fee' => 0, 'net_amount' => 50, 'currency' => 'SAR', 'status' => 'rejected', 'idempotency_key' => 'k3', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $b = $this->ledger->balances($a['provider']);

        $this->assertSame(200.0, $b['reserved'], 'Only the one still waiting is held back');
        $this->assertSame(100.0, $b['total_withdrawn']);
        $this->assertSame(900.0, $b['total_earnings'], 'Earned, net of commission, whether or not it was withdrawn');
    }

    public function test_a_providers_wallet_is_theirs_alone(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $b = $this->approvedProvider(['commission_rate' => 0]);
        $this->fundWallet($a, 1000);
        $this->fundWallet($b, 300);

        $this->assertSame(900.0, $this->ledger->balances($a['provider'])['available']);
        $this->assertSame(300.0, $this->ledger->balances($b['provider'])['available']);
    }

    public function test_amounts_stay_at_two_decimals(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 7.5]);
        $id = $this->fundWallet($a, 33.33);

        $this->assertSame([['booking_income', 33.33], ['platform_fee', -2.5]], $this->entries($id), '7.5% of 33.33 is 2.49975, kept as 2.50');
    }

    // --- How bookings get into the ledger ------------------------------------------------------------------------

    public function test_completing_a_booking_posts_it(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $id = $this->makeBooking($a, $this->customerNamed('Sara'), '2026-11-14', 'confirmed', 1000);
        $this->makePayment($id, 1000);

        app(ReservationService::class)->completePastReservations();

        $this->assertSame([['booking_income', 1000.0], ['platform_fee', -100.0]], $this->entries($id));
    }

    public function test_the_nightly_command_posts_what_is_missing_and_nothing_twice(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $b = $this->approvedProvider(['commission_rate' => 0]);
        $first = $this->makeBooking($a, $this->customerNamed('Sara'), '2026-11-10', 'completed', 1000);
        $second = $this->makeBooking($b, $this->customerNamed('Noor'), '2026-11-11', 'completed', 400);

        $this->artisan('wallet:sync')->expectsOutputToContain('Posted 2 completed booking(s)')->assertSuccessful();
        $this->artisan('wallet:sync')->expectsOutputToContain('Posted 0 completed booking(s)')->assertSuccessful();

        $this->assertCount(2, $this->entries($first));
        $this->assertCount(1, $this->entries($second));
        $this->assertContains('wallet:sync', array_keys(Artisan::all()));
    }

    public function test_balances_add_up_across_bookings(): void
    {
        $a = $this->approvedProvider(['commission_rate' => 10]);
        $this->fundWallet($a, 1000);
        $this->fundWallet($a, 500);

        $this->assertSame(1350.0, $this->ledger->balances($a['provider'])['available']);
    }
}
