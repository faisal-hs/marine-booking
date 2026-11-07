<?php

namespace Database\Seeders\Bulk;

use App\Models\Provider;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Withdrawals: about one provider in seven who has money available has asked to be paid, once to three times. Each has the bank account it was
 * asked for (the provider gets one), an outcome, and the ledger rows that outcome leaves: the amount reserved when asked, and given back when the
 * request was rejected, failed or cancelled. A request never asks for more than was available at the time, so no provider is left overdrawn.
 * Volume at scale 1: about 400.
 * Prerequisites: BulkWalletEntrySeeder, BulkAdminSeeder (who paid).
 */
class BulkWithdrawalSeeder extends BulkSeeder
{
    private const BANKS = ['Al Rajhi Bank', 'Saudi National Bank', 'Riyad Bank', 'Banque Saudi Fransi', 'Alinma Bank'];

    private const REASONS = [
        'rejected' => ['The bank account name does not match the business name.', 'Please confirm the account details first.'],
        'failed' => ['The bank returned the transfer.', 'The IBAN was not accepted by the receiving bank.'],
    ];

    protected function seed(): void
    {
        $this->needs('wallet_entries', 'BulkWalletEntrySeeder');

        $ledger = app(LedgerService::class);
        $admins = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereIn('roles.name', ['super_admin', 'admin'])->pluck('model_has_roles.model_id')->all();
        $have = array_flip(DB::table('withdrawals')->distinct()->pluck('provider_id')->all());
        $currency = config('platform.currency');

        foreach (DB::table('wallet_entries')->distinct()->orderBy('provider_id')->pluck('provider_id') as $id) {
            if (isset($have[$id]) || ! $this->stable("withdrawal:{$id}", 14) || ! ($provider = Provider::find($id))) {
                continue;
            }

            $available = $ledger->balances($provider)['available'];
            if ($available < 50) {
                continue;
            }

            $bank = [
                'account_holder_name' => $provider->business_name, 'bank_name' => $this->pick(self::BANKS),
                'iban' => 'SA03'.str_pad((string) $id, 20, '0', STR_PAD_LEFT), 'swift_code' => null, 'verification_status' => 'approved',
            ];
            $provider->forceFill(['bank_account' => $bank])->save();

            foreach (range(1, $this->rand(1, 3)) as $n) {
                $amount = round($available * $this->pick([0.2, 0.3, 0.5]), 2);
                if ($amount < 10) {
                    break;
                }

                $status = $this->weighted(['paid' => 50, 'pending' => 15, 'processing' => 10, 'rejected' => 12, 'failed' => 8, 'cancelled' => 5]);
                $held = in_array($status, ['pending', 'processing', 'paid'], true);
                $asked = $this->daysAgo(1, 60);
                $handled = in_array($status, ['pending', 'cancelled'], true) ? null : $asked->copy()->addDays($this->rand(1, 3));
                $handled = $handled && $handled->greaterThan($this->now) ? $this->now->copy() : $handled;

                $withdrawalId = DB::table('withdrawals')->insertGetId([
                    'uuid' => $this->uuid('withdrawal', "{$id}-{$n}"), 'provider_id' => $id, 'amount' => $amount, 'fee' => 0, 'net_amount' => $amount, 'currency' => $currency,
                    'status' => $status, 'idempotency_key' => "bulk-{$id}-{$n}", 'bank_snapshot' => Crypt::encryptString(json_encode($bank)),
                    'payout_reference' => $status === 'paid' ? 'TRF'.str_pad((string) $id, 6, '0', STR_PAD_LEFT).$n : null,
                    'status_reason' => self::REASONS[$status] ?? null ? $this->pick(self::REASONS[$status]) : null,
                    'processed_by_user_id' => $handled && $admins ? $this->pick($admins) : null, 'processed_at' => $handled ? $this->ts($handled) : null,
                    'created_at' => $this->ts($asked), 'updated_at' => $this->ts($handled ?? $asked),
                ]);
                $this->created++;

                $rows = [$this->entry($id, $withdrawalId, WalletEntry::WITHDRAWAL_RESERVE, -$amount, "Withdrawal request #{$withdrawalId}", "withdrawal:{$withdrawalId}:reserve", $currency, $asked)];
                if (! $held) {
                    $rows[] = $this->entry($id, $withdrawalId, WalletEntry::WITHDRAWAL_RELEASE, $amount, "Withdrawal #{$withdrawalId} returned to your balance", "withdrawal:{$withdrawalId}:release", $currency, $handled ?? $asked);
                }
                $this->insert('wallet_entries', $rows);

                if ($held) {
                    $available = round($available - $amount, 2);
                }
            }
        }
    }

    private function entry(int $provider, int $withdrawal, string $type, float $amount, string $description, string $reference, string $currency, \Carbon\Carbon $at): array
    {
        return [
            'uuid' => $this->uuid('wallet', $reference), 'provider_id' => $provider, 'reservation_id' => null, 'withdrawal_id' => $withdrawal, 'type' => $type,
            'amount' => $amount, 'currency' => $currency, 'available_at' => null, 'occurred_on' => null, 'description' => $description, 'reference' => $reference,
            'meta' => null, 'created_at' => $this->ts($at), 'updated_at' => $this->ts($at),
        ];
    }
}
