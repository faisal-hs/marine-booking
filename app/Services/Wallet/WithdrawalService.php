<?php

namespace App\Services\Wallet;

use App\Exceptions\ProviderApiException;
use App\Models\Provider;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Notifications\Wallet\Admin\WithdrawalRequestedAdminNotification;
use App\Notifications\Wallet\WithdrawalStatusNotification;
use App\Services\NotificationDispatchService;
use App\Support\ProviderApi\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Withdrawals. The system never moves money to a bank: a provider ASKS, an admin PAYS by hand and records what happened.
 *
 *   request    the amount is reserved at once (a ledger row of -amount), so it cannot be asked for twice or spent
 *   processing an admin has started the transfer
 *   paid       an admin made the transfer and recorded its reference: the reservation becomes the final debit
 *   rejected / failed   an admin refused, or the transfer failed: the amount is RELEASED back to the available balance
 *
 * Every change of status is decided under a lock and only from the right status, and every ledger row has a unique reference, so a withdrawal can
 * be reserved once and released once however many times a button is pressed.
 */
class WithdrawalService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly NotificationDispatchService $dispatcher,
    ) {}

    public function request(Provider $provider, float $amount, string $key): Withdrawal
    {
        $withdrawal = DB::transaction(function () use ($provider, $amount, $key) {
            // The provider's row is the lock: two requests of one provider are served one after the other.
            $provider = Provider::query()->lockForUpdate()->findOrFail($provider->id);

            if (Withdrawal::where('provider_id', $provider->id)->where('idempotency_key', $key)->exists()) {
                throw ProviderApiException::conflict('A withdrawal with this idempotency_key was already requested.', ErrorCode::DUPLICATE_WITHDRAWAL);
            }

            if (empty($provider->bank_account)) {
                throw new ProviderApiException('Add your bank account before requesting a withdrawal.', 422, ErrorCode::BANK_ACCOUNT_REQUIRED);
            }

            $this->ledger->syncProvider($provider);
            $amount = round($amount, 2);

            if ($amount > $this->ledger->balances($provider)['available']) {
                throw new ProviderApiException('The amount is more than your available balance.', 422, ErrorCode::INSUFFICIENT_AVAILABLE_BALANCE);
            }

            $fee = round($amount * (float) config('wallet.withdrawal_fee_percent') / 100, 2);

            $withdrawal = Withdrawal::create([
                'provider_id' => $provider->id, 'amount' => $amount, 'fee' => $fee, 'net_amount' => round($amount - $fee, 2), 'currency' => config('platform.currency'),
                'status' => Withdrawal::PENDING, 'idempotency_key' => $key, 'bank_snapshot' => $provider->bank_account,
            ]);

            $this->entry($withdrawal, WalletEntry::WITHDRAWAL_RESERVE, -$withdrawal->amount, "Withdrawal request #{$withdrawal->id}", "withdrawal:{$withdrawal->id}:reserve");

            return $withdrawal;
        });

        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatchOnce("admin.withdrawal.requested:{$withdrawal->id}", $admin, new WithdrawalRequestedAdminNotification($withdrawal));
        }

        return $withdrawal;
    }

    public function startProcessing(Withdrawal $withdrawal, User $admin): Withdrawal
    {
        return $this->move($withdrawal, $admin, [Withdrawal::PENDING], Withdrawal::PROCESSING);
    }

    public function markPaid(Withdrawal $withdrawal, User $admin, string $reference): Withdrawal
    {
        return $this->move($withdrawal, $admin, [Withdrawal::PENDING, Withdrawal::PROCESSING], Withdrawal::PAID, ['payout_reference' => $reference]);
    }

    public function reject(Withdrawal $withdrawal, User $admin, string $reason): Withdrawal
    {
        return $this->move($withdrawal, $admin, [Withdrawal::PENDING, Withdrawal::PROCESSING], Withdrawal::REJECTED, ['status_reason' => $reason], release: true);
    }

    public function fail(Withdrawal $withdrawal, User $admin, string $reason): Withdrawal
    {
        return $this->move($withdrawal, $admin, [Withdrawal::PROCESSING], Withdrawal::FAILED, ['status_reason' => $reason], release: true);
    }

    /** @param  list<string>  $from  @param  array<string, mixed>  $changes */
    private function move(Withdrawal $withdrawal, User $admin, array $from, string $to, array $changes = [], bool $release = false): Withdrawal
    {
        $withdrawal = DB::transaction(function () use ($withdrawal, $admin, $from, $to, $changes, $release) {
            $withdrawal = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! in_array($withdrawal->status, $from, true)) {
                throw ValidationException::withMessages(['status' => ["A withdrawal that is {$withdrawal->status} cannot become {$to}."]]);
            }

            $withdrawal->update($changes + ['status' => $to, 'processed_by_user_id' => $admin->id, 'processed_at' => now()]);

            if ($release) {
                $this->entry($withdrawal, WalletEntry::WITHDRAWAL_RELEASE, $withdrawal->amount, "Withdrawal #{$withdrawal->id} returned to your balance", "withdrawal:{$withdrawal->id}:release");
            }

            return $withdrawal;
        });

        $this->dispatcher->dispatchOnce("provider.withdrawal.{$to}:{$withdrawal->id}", $withdrawal->provider->user, new WithdrawalStatusNotification($withdrawal));

        return $withdrawal;
    }

    private function entry(Withdrawal $withdrawal, string $type, float $amount, string $description, string $reference): WalletEntry
    {
        return WalletEntry::firstOrCreate(['reference' => $reference], [
            'provider_id' => $withdrawal->provider_id, 'withdrawal_id' => $withdrawal->id, 'type' => $type, 'amount' => $amount,
            'currency' => $withdrawal->currency, 'description' => $description,
        ]);
    }

    /** The admins who may pay out. */
    private function admins()
    {
        try {
            return User::permission('withdrawals.process')->get();
        } catch (PermissionDoesNotExist) {
            return collect();
        }
    }
}
