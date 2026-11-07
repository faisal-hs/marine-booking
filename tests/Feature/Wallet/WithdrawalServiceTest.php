<?php

namespace Tests\Feature\Wallet;

use App\Exceptions\ProviderApiException;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Notifications\Wallet\Admin\WithdrawalRequestedAdminNotification;
use App\Notifications\Wallet\WithdrawalStatusNotification;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\WithdrawalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * Withdrawals. A provider ASKS, an admin PAYS by hand. The amount is reserved the moment it is asked for; it is released if the request is rejected or
 * the transfer fails; and each request has exactly one outcome, however many times a button is pressed.
 */
class WithdrawalServiceTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private User $admin;

    private WithdrawalService $withdrawals;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
        $this->a['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($this->a, 1000);   // 900 available after the commission
        $this->admin = $this->makeAdmin();
        $this->withdrawals = app(WithdrawalService::class);
        $this->ledger = app(LedgerService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ask(float $amount = 500, string $key = 'wd-key-0001'): Withdrawal
    {
        return $this->withdrawals->request($this->a['provider']->refresh(), $amount, $key);
    }

    private function balances(): array
    {
        return $this->ledger->balances($this->a['provider']->refresh());
    }

    private function refusal(callable $do): ProviderApiException
    {
        try {
            $do();
        } catch (ProviderApiException $e) {
            return $e;
        }

        $this->fail('It should have been refused');
    }

    // --- Asking ------------------------------------------------------------------------------------------------------

    public function test_the_amount_is_reserved_the_moment_it_is_asked_for(): void
    {
        $w = $this->ask(500);

        $this->assertSame(['pending', 500.0, 0.0, 500.0], [$w->status, $w->amount, $w->fee, $w->net_amount]);
        $this->assertSame(['available' => 400.0, 'reserved' => 500.0], array_intersect_key($this->balances(), ['available' => 1, 'reserved' => 1]));
        $this->assertSame([-500.0], WalletEntry::where('withdrawal_id', $w->id)->pluck('amount')->all(), 'One debit in the ledger');
    }

    public function test_the_same_money_cannot_be_asked_for_twice(): void
    {
        $this->ask(500, 'wd-key-0001');

        $e = $this->refusal(fn () => $this->ask(500, 'wd-key-0002'));

        $this->assertSame(['INSUFFICIENT_AVAILABLE_BALANCE', 422], [$e->errorCode, $e->status]);
        $this->assertSame(1, Withdrawal::count());
        $this->ask(400, 'wd-key-0003');
        $this->assertSame(0.0, $this->balances()['available'], 'Everything is spoken for');
    }

    public function test_more_than_the_available_balance_is_refused_and_exactly_the_balance_is_fine(): void
    {
        $e = $this->refusal(fn () => $this->ask(900.01));
        $this->assertSame('INSUFFICIENT_AVAILABLE_BALANCE', $e->errorCode);
        $this->assertSame(0, Withdrawal::count());
        $this->assertSame(0, WalletEntry::where('type', 'withdrawal_reserve')->count(), 'A refusal leaves no trace in the ledger');

        $this->ask(900);
        $this->assertSame(0.0, $this->balances()['available']);
    }

    public function test_money_still_inside_the_waiting_period_cannot_be_withdrawn(): void
    {
        config(['wallet.settlement_delay_days' => 3]);
        $b = $this->approvedProvider(['commission_rate' => 0]);
        $b['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($b, 600, '2026-11-14');

        $e = $this->refusal(fn () => $this->withdrawals->request($b['provider']->refresh(), 100, 'wd-key-0001'));

        $this->assertSame('INSUFFICIENT_AVAILABLE_BALANCE', $e->errorCode);
        $this->assertSame(600.0, $this->ledger->balances($b['provider'])['pending']);
    }

    public function test_the_same_idempotency_key_is_refused_a_second_time_and_reserves_nothing_more(): void
    {
        $this->ask(100, 'wd-same-key');

        $e = $this->refusal(fn () => $this->ask(100, 'wd-same-key'));

        $this->assertSame(['DUPLICATE_WITHDRAWAL', 409], [$e->errorCode, $e->status]);
        $this->assertSame(1, Withdrawal::count());
        $this->assertSame(1, WalletEntry::where('type', 'withdrawal_reserve')->count());
    }

    public function test_two_providers_may_use_the_same_key(): void
    {
        $b = $this->approvedProvider(['commission_rate' => 0]);
        $b['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($b, 300);

        $this->ask(100, 'shared-key-1');
        $this->withdrawals->request($b['provider']->refresh(), 100, 'shared-key-1');

        $this->assertSame(2, Withdrawal::count());
    }

    public function test_a_bank_account_is_needed_first(): void
    {
        $this->a['provider']->forceFill(['bank_account' => null])->save();

        $e = $this->refusal(fn () => $this->ask(100));

        $this->assertSame(['BANK_ACCOUNT_REQUIRED', 422], [$e->errorCode, $e->status]);
    }

    public function test_the_withdrawal_keeps_the_account_it_was_asked_for(): void
    {
        $w = $this->ask(100);
        $this->a['provider']->forceFill(['bank_account' => $this->bankAccount(['bank_name' => 'Another Bank', 'iban' => 'SA4420000001234567891234'])])->save();

        $this->assertSame('Al Rajhi Bank', $w->refresh()->bank_snapshot['bank_name']);
        $this->assertSame('SA0380000000608010167519', $w->bank_snapshot['iban']);
    }

    public function test_a_withdrawal_fee_comes_from_the_configuration_and_the_provider_is_paid_what_is_left(): void
    {
        config(['wallet.withdrawal_fee_percent' => 5]);

        $w = $this->ask(400);

        $this->assertSame([400.0, 20.0, 380.0], [$w->amount, $w->fee, $w->net_amount]);
        $this->assertSame(500.0, $this->balances()['available'], 'The whole amount is reserved; the fee is part of it');
    }

    // --- What an admin does ---------------------------------------------------------------------------------------

    public function test_paying_makes_the_reservation_the_final_debit(): void
    {
        $w = $this->ask(500);

        $this->withdrawals->startProcessing($w, $this->admin);
        $paid = $this->withdrawals->markPaid($w->refresh(), $this->admin, 'TRF-2026-0042');

        $this->assertSame(['paid', 'TRF-2026-0042', $this->admin->id], [$paid->status, $paid->payout_reference, $paid->processed_by_user_id]);
        $this->assertNotNull($paid->processed_at);
        $b = $this->balances();
        $this->assertSame([400.0, 0.0, 500.0], [$b['available'], $b['reserved'], $b['total_withdrawn']], 'Gone for good: not reserved any more, not given back');
        $this->assertSame(1, WalletEntry::where('withdrawal_id', $w->id)->count(), 'No second debit');
    }

    public function test_a_pending_request_can_be_paid_without_the_processing_step(): void
    {
        $w = $this->ask(100);

        $this->assertSame('paid', $this->withdrawals->markPaid($w, $this->admin, 'TRF-1')->status);
    }

    public function test_rejecting_gives_the_money_back_exactly_once(): void
    {
        $w = $this->ask(500);

        $this->withdrawals->reject($w, $this->admin, 'The account name does not match');

        $this->assertSame(['available' => 900.0, 'reserved' => 0.0], array_intersect_key($this->balances(), ['available' => 1, 'reserved' => 1]));
        $this->assertSame('The account name does not match', $w->refresh()->status_reason);

        try {
            $this->withdrawals->reject($w->refresh(), $this->admin, 'Again');
            $this->fail('A second rejection must be refused');
        } catch (ValidationException) {
        }
        $this->assertSame(900.0, $this->balances()['available'], 'Not released twice');
        $this->assertSame(1, WalletEntry::where('type', 'withdrawal_release')->count());
    }

    public function test_a_failed_transfer_gives_the_money_back_but_only_a_transfer_that_was_started_can_fail(): void
    {
        $w = $this->ask(500);

        try {
            $this->withdrawals->fail($w, $this->admin, 'Bank returned it');
            $this->fail('A transfer that was never started cannot fail');
        } catch (ValidationException) {
        }
        $this->assertSame(400.0, $this->balances()['available'], 'Still reserved');

        $this->withdrawals->startProcessing($w->refresh(), $this->admin);
        $this->withdrawals->fail($w->refresh(), $this->admin, 'Bank returned it');

        $this->assertSame(['failed', 900.0], [$w->refresh()->status, $this->balances()['available']]);
    }

    public function test_a_finished_withdrawal_cannot_be_changed_again(): void
    {
        $paid = $this->ask(100, 'wd-key-paid');
        $this->withdrawals->markPaid($paid, $this->admin, 'TRF-1');
        $rejected = $this->ask(100, 'wd-key-rejected');
        $this->withdrawals->reject($rejected, $this->admin, 'No');

        foreach ([
            fn () => $this->withdrawals->reject($paid->refresh(), $this->admin, 'Too late'),
            fn () => $this->withdrawals->markPaid($paid->refresh(), $this->admin, 'TRF-2'),
            fn () => $this->withdrawals->startProcessing($paid->refresh(), $this->admin),
            fn () => $this->withdrawals->markPaid($rejected->refresh(), $this->admin, 'TRF-3'),
            fn () => $this->withdrawals->startProcessing($rejected->refresh(), $this->admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A finished withdrawal must not move');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(['paid', 'rejected'], [$paid->refresh()->status, $rejected->refresh()->status]);
        $this->assertSame(800.0, $this->balances()['available'], '900 - the 100 that was paid; the rejected 100 came back');
    }

    // --- Who is told ----------------------------------------------------------------------------------------------

    public function test_the_admins_who_pay_out_are_told_of_a_request(): void
    {
        $this->ask(250);

        $note = $this->admin->notifications()->where('type', WithdrawalRequestedAdminNotification::class)->first();

        $this->assertNotNull($note);
        $this->assertStringContainsString('Red Sea Divers', $note->data['message']);
        $this->assertStringContainsString('250', $note->data['message']);
    }

    public function test_the_provider_is_told_each_outcome(): void
    {
        $paid = $this->ask(100, 'wd-key-a');
        $this->withdrawals->startProcessing($paid, $this->admin);
        $this->withdrawals->markPaid($paid->refresh(), $this->admin, 'TRF-77');
        $rejected = $this->ask(100, 'wd-key-b');
        $this->withdrawals->reject($rejected, $this->admin, 'Please confirm the account');

        $messages = $this->a['user']->notifications()->where('type', WithdrawalStatusNotification::class)->get()->pluck('data.message');

        $this->assertCount(3, $messages);
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'is being processed')));
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'was paid') && str_contains($m, 'TRF-77')));
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'rejected') && str_contains($m, 'Please confirm the account')));
    }
}
