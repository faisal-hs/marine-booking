<?php

namespace Tests\Feature\Wallet;

use App\Exceptions\ProviderApiException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\WithdrawalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The ways money goes wrong, each tried on purpose: the same withdrawal twice, more than there is, a decision made twice, a refund after the money was
 * posted or even paid out, a commission changed after the booking, amounts at the edge of a cent. In each case the ledger must still add up.
 */
class WalletBreakageTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private User $admin;

    private LedgerService $ledger;

    private WithdrawalService $withdrawals;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
        $this->a['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->admin = $this->makeAdmin();
        $this->ledger = app(LedgerService::class);
        $this->withdrawals = app(WithdrawalService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function balances(): array
    {
        return $this->ledger->balances($this->a['provider']->refresh());
    }

    private function ask(float $amount, string $key): Withdrawal
    {
        return $this->withdrawals->request($this->a['provider']->refresh(), $amount, $key);
    }

    /** The one rule that must hold whatever happened: every figure comes from the ledger and they agree with each other. */
    private function assertLedgerBalances(): void
    {
        $b = $this->balances();
        $entries = WalletEntry::where('provider_id', $this->a['provider']->id);
        $all = round((float) (clone $entries)->sum('amount'), 2);

        $this->assertEqualsWithDelta($all, $b['available'] + $b['pending'], 0.001, 'available + pending is the sum of the ledger');
        $this->assertSame($b['reserved'], round((float) Withdrawal::where('provider_id', $this->a['provider']->id)->whereIn('status', ['pending', 'processing'])->sum('amount'), 2));
        $reserves = (float) (clone $entries)->where('type', 'withdrawal_reserve')->sum('amount');
        $releases = (float) (clone $entries)->where('type', 'withdrawal_release')->sum('amount');
        $this->assertEqualsWithDelta(-($reserves + $releases), $b['reserved'] + $b['total_withdrawn'], 0.001, 'what left the wallet is exactly what is held or paid');
    }

    // --- The same request, twice -------------------------------------------------------------------------------------

    public function test_a_double_tap_reserves_the_money_once(): void
    {
        $this->fundWallet($this->a, 1000);

        $this->ask(500, 'wd-double-tap');
        foreach (range(1, 4) as $_) {
            try {
                $this->ask(500, 'wd-double-tap');
                $this->fail('A repeated key must be refused');
            } catch (ProviderApiException $e) {
                $this->assertSame('DUPLICATE_WITHDRAWAL', $e->errorCode);
            }
        }

        $this->assertSame(1, Withdrawal::count());
        $this->assertSame(1, WalletEntry::where('type', 'withdrawal_reserve')->count());
        $this->assertSame(400.0, $this->balances()['available']);
        $this->assertLedgerBalances();
    }

    public function test_many_different_requests_can_never_spend_more_than_there_is(): void
    {
        $this->fundWallet($this->a, 1000);   // 900 available
        $granted = 0.0;

        foreach ([300, 300, 300, 300, 1, 0.01] as $n => $amount) {
            try {
                $granted += $this->ask($amount, 'wd-many-'.$n)->amount;
            } catch (ProviderApiException $e) {
                $this->assertSame('INSUFFICIENT_AVAILABLE_BALANCE', $e->errorCode);
            }
        }

        $this->assertSame(900.0, round($granted, 2), 'Exactly the balance was handed out, no more');
        $this->assertSame(0.0, $this->balances()['available']);
        $this->assertLedgerBalances();
    }

    public function test_the_same_request_through_the_api_twice_in_a_row_reserves_once(): void
    {
        $this->fundWallet($this->a, 1000);
        $body = ['amount' => 600, 'idempotency_key' => 'wd-api-twice'];

        $this->hit($this->a['token'], 'POST', '/api/v1/provider/withdrawals', $body)->assertCreated();
        $this->hit($this->a['token'], 'POST', '/api/v1/provider/withdrawals', $body)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_WITHDRAWAL');
        $this->hit($this->a['token'], 'POST', '/api/v1/provider/withdrawals', ['amount' => 600, 'idempotency_key' => 'wd-api-other'])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_AVAILABLE_BALANCE');

        $this->assertSame(1, Withdrawal::count());
        $this->assertLedgerBalances();
    }

    public function test_money_is_held_for_the_whole_time_a_withdrawal_is_waiting_or_being_processed_and_not_a_moment_longer(): void
    {
        $this->fundWallet($this->a, 1000);
        $w = $this->ask(300, 'wd-held');
        $held = fn () => $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings')->json('data.reserved_balance');

        $this->assertSame([300.0, 300], [$this->balances()['reserved'], $held()], 'Waiting');

        $this->withdrawals->startProcessing($w, $this->admin);
        $this->assertSame([300.0, 300], [$this->balances()['reserved'], $held()], 'Being processed: still held');
        $this->assertSame(300, $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/report?year=2026')->json('data.pending_payout'));

        $this->withdrawals->markPaid($w->refresh(), $this->admin, 'TRF-1');
        $this->assertSame([0.0, 0], [$this->balances()['reserved'], $held()], 'Paid: no longer held, it has gone');
        $this->assertSame(300.0, $this->balances()['total_withdrawn']);
    }

    // --- Decisions made twice ----------------------------------------------------------------------------------------

    public function test_rejecting_and_failing_over_and_over_returns_the_money_only_once(): void
    {
        $this->fundWallet($this->a, 1000);
        $rejected = $this->ask(300, 'wd-reject');
        $failed = $this->ask(200, 'wd-fail');
        $this->withdrawals->startProcessing($failed, $this->admin);

        for ($i = 0; $i < 3; $i++) {
            foreach ([
                fn () => $this->withdrawals->reject($rejected->refresh(), $this->admin, 'Again'),
                fn () => $this->withdrawals->fail($failed->refresh(), $this->admin, 'Again'),
            ] as $attempt) {
                try {
                    $attempt();
                } catch (ValidationException) {
                }
            }
        }

        $this->assertSame(['rejected', 'failed'], [$rejected->refresh()->status, $failed->refresh()->status]);
        $this->assertSame(2, WalletEntry::where('type', 'withdrawal_release')->count(), 'One release each, not one per press');
        $this->assertSame(900.0, $this->balances()['available'], 'Everything is back, and not a riyal more');
        $this->assertLedgerBalances();
    }

    public function test_a_paid_withdrawal_can_never_be_given_back(): void
    {
        $this->fundWallet($this->a, 1000);
        $w = $this->ask(400, 'wd-paid');
        $this->withdrawals->markPaid($w, $this->admin, 'TRF-1');

        foreach ([
            fn () => $this->withdrawals->reject($w->refresh(), $this->admin, 'Changed my mind'),
            fn () => $this->withdrawals->fail($w->refresh(), $this->admin, 'Changed my mind'),
            fn () => $this->withdrawals->markPaid($w->refresh(), $this->admin, 'TRF-2'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A paid withdrawal must not move');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(['paid', 'TRF-1'], [$w->refresh()->status, $w->payout_reference]);
        $this->assertSame(500.0, $this->balances()['available']);
        $this->assertSame(0, WalletEntry::where('type', 'withdrawal_release')->count());
        $this->assertLedgerBalances();
    }

    // --- A refund after the money was posted, or paid out ---------------------------------------------------------------

    public function test_a_refund_after_posting_and_after_a_withdrawal_leaves_a_negative_balance_and_blocks_more(): void
    {
        $id = $this->fundWallet($this->a, 1000);          // 900 net
        $w = $this->ask(900, 'wd-everything');
        $this->withdrawals->markPaid($w, $this->admin, 'TRF-1');
        $this->assertSame(0.0, $this->balances()['available']);

        // The customer is refunded in full AFTER the provider already took everything out.
        Payment::where('reservation_id', $id)->update(['refunded_amount' => 1000, 'status' => 'refunded']);
        $this->ledger->syncBooking(Reservation::find($id));

        $this->assertSame(-900.0, $this->balances()['available'], 'The provider now owes the platform what was refunded');
        $this->assertSame(0.0, round(WalletEntry::where('reservation_id', $id)->sum('amount'), 2), 'The booking itself earns nothing');

        try {
            $this->ask(1, 'wd-more');
            $this->fail('A provider who owes money cannot withdraw');
        } catch (ProviderApiException $e) {
            $this->assertSame('INSUFFICIENT_AVAILABLE_BALANCE', $e->errorCode);
        }

        $this->fundWallet($this->a, 500);                  // 450 net: the debt is worked off first
        $this->assertSame(-450.0, $this->balances()['available']);
        $this->assertLedgerBalances();
    }

    public function test_a_refund_while_a_withdrawal_is_waiting_cannot_make_the_reserved_money_vanish_or_double(): void
    {
        $id = $this->fundWallet($this->a, 1000);          // 900
        $w = $this->ask(500, 'wd-waiting');               // 400 left
        Payment::where('reservation_id', $id)->update(['refunded_amount' => 400]);
        $this->ledger->syncBooking(Reservation::find($id));   // net becomes 1000 - 400 - 60 = 540

        $this->assertSame(500.0, $this->balances()['reserved'], 'The reservation is untouched by the refund');
        $this->assertSame(40.0, $this->balances()['available'], '540 earned, 500 held');

        $this->withdrawals->reject($w, $this->admin, 'No');
        $this->assertSame(540.0, $this->balances()['available'], 'Rejecting gives back exactly what was held');
        $this->assertLedgerBalances();
    }

    // --- The commission changed after the booking ------------------------------------------------------------------------

    public function test_changing_the_type_of_commission_after_bookings_completed_leaves_them_as_they_were_posted(): void
    {
        $before = $this->fundWallet($this->a, 1000);       // 10% = 100

        $this->a['provider']->update(['commission_type' => 'fixed', 'commission_rate' => 25]);
        Payment::where('reservation_id', $before)->update(['refunded_amount' => 500]);
        $this->ledger->syncBooking(Reservation::find($before));

        $this->assertSame(450.0, round(WalletEntry::where('reservation_id', $before)->sum('amount'), 2), 'Still 10%: 1000 - 500 - 10% of 500');
        $after = $this->fundWallet($this->a, 1000);
        $this->assertSame(975.0, round(WalletEntry::where('reservation_id', $after)->sum('amount'), 2), 'A later booking pays the new fixed 25');
        $this->assertLedgerBalances();
    }

    public function test_a_commission_raised_to_a_hundred_percent_costs_only_the_bookings_that_come_after(): void
    {
        $first = $this->fundWallet($this->a, 400);
        $this->a['provider']->update(['commission_rate' => 100]);
        $second = $this->fundWallet($this->a, 400);

        $this->assertSame([360.0, 0.0], [round(WalletEntry::where('reservation_id', $first)->sum('amount'), 2), round(WalletEntry::where('reservation_id', $second)->sum('amount'), 2)]);
    }

    // --- The edge of a cent ----------------------------------------------------------------------------------------------

    public function test_a_half_cent_cannot_be_used_to_withdraw_more_than_the_balance(): void
    {
        $this->a['provider']->update(['commission_rate' => 0]);
        $this->fundWallet($this->a, 100);

        try {
            $this->ask(100.005, 'wd-half-cent');   // rounds to 100.01
            $this->fail('100.01 is more than 100.00');
        } catch (ProviderApiException $e) {
            $this->assertSame('INSUFFICIENT_AVAILABLE_BALANCE', $e->errorCode);
        }

        $this->assertSame(100.0, $this->ask(100.004, 'wd-just-under')->amount, '100.004 is 100.00');
        $this->assertSame(0.0, $this->balances()['available']);
    }

    public function test_floating_point_noise_never_leaves_dust_in_the_balance(): void
    {
        $this->a['provider']->update(['commission_rate' => 0]);
        foreach ([0.1, 0.2, 33.33, 66.67, 0.3] as $amount) {
            $this->fundWallet($this->a, $amount);
        }

        $this->assertSame(100.6, $this->balances()['available']);
        $this->ask(100.6, 'wd-all-of-it');
        $this->assertSame(0.0, $this->balances()['available']);
        $this->assertLedgerBalances();
    }

    public function test_a_fee_can_never_be_more_than_the_amount(): void
    {
        config(['wallet.withdrawal_fee_percent' => 100]);
        $this->fundWallet($this->a, 1000);

        $w = $this->ask(200, 'wd-full-fee');

        $this->assertSame([200.0, 200.0, 0.0], [$w->amount, $w->fee, $w->net_amount]);
    }
}
