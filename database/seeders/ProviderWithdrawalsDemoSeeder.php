<?php

namespace Database\Seeders;

use App\Models\Provider;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Database\Seeders\Bulk\BulkSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Withdrawals for the demo providers, many for each and in every status, so the wallet screen, the withdrawal list and its filters, and the admin's queue all
 * have something to show. Run it AFTER the demo bookings (ProviderAnalyticsDemoSeeder or ProviderMonthlyReservationSeeder): what a provider withdraws is what
 * their completed trips earned.
 *
 *     php artisan db:seed --class=ProviderWithdrawalsDemoSeeder
 *
 * For each verified provider with an active account and something to withdraw:
 *
 *  - A BANK ACCOUNT, if they have none (a demo one, approved), because a withdrawal needs one; one that exists is left as it is. Each withdrawal keeps a
 *    snapshot of the account as it was.
 *  - EIGHT TO FOURTEEN WITHDRAWALS, and every provider gets at least one in each of the six statuses:
 *      pending      just asked for, waiting for an admin (the last few days)
 *      processing   an admin has started it (the last week)
 *      paid         sent, with the bank's transfer reference (the most of them, and the oldest)
 *      rejected     refused by an admin, with the reason
 *      failed       the transfer was returned by the bank, with the reason
 *      cancelled    taken back by the provider
 *  - THE MONEY TRAIL, exactly as the withdrawal service leaves it: a reserve entry in the wallet ledger when it was asked for, and a release entry giving the
 *    money back when it was rejected, failed or cancelled. So the provider's available balance is what is left after the pending, processing and paid ones.
 *    The ones that hold money together never ask for more than 85% of what the provider could withdraw, so no balance goes below zero; each is at least the
 *    minimum withdrawal, and carries the fee the platform charges.
 *
 * Every figure comes from the seeder's own random engine, started afresh for each provider, so it is the same on every run; running it again adds nothing.
 * (A provider's plan is worked out from what they could withdraw BEFORE these withdrawals, so it does not drift as the balance changes.) Withdrawals are
 * never dated in the future. It is refused in production, like the other demo seeders.
 *
 * Volume at scale 1 (the demo data): about 10 providers with enough earnings, so about 120 withdrawals (about 35 paid, 15 pending, 15 processing, 17 rejected, 18 failed and 20 cancelled).
 * Prerequisites: ProviderAnalyticsDemoSeeder (or ProviderMonthlyReservationSeeder): completed bookings, which are the earnings. DatabaseSeeder for the providers.
 */
class ProviderWithdrawalsDemoSeeder extends BulkSeeder
{
    private const KEY_PREFIX = 'demo-wd-';

    private const BANKS = ['Al Rajhi Bank', 'Saudi National Bank', 'Riyad Bank', 'Banque Saudi Fransi', 'Alinma Bank', 'Arab National Bank'];

    private const REASONS = [
        'rejected' => ['The bank account name does not match the business name.', 'Please confirm the account details first.', 'The amount is above what the account is verified for.'],
        'failed' => ['The bank returned the transfer.', 'The IBAN was not accepted by the receiving bank.', 'The receiving account is closed.'],
        'cancelled' => ['Cancelled by the provider.'],
    ];

    /** The statuses that hold the money back: it has left the available balance, and stays out of it. */
    private const HOLDING = ['pending', 'processing', 'paid'];

    private array $made = [];

    protected function seed(): void
    {
        $this->needs('reservations', 'ProviderAnalyticsDemoSeeder (or ProviderMonthlyReservationSeeder): completed bookings are what a provider earns and withdraws', fn ($q) => $q->where('status', 'completed'));

        $ledger = app(LedgerService::class);
        $admins = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereIn('roles.name', ['super_admin', 'admin'])->pluck('model_has_roles.model_id')->all();
        $providers = DB::table('providers as p')->join('users as u', 'u.id', '=', 'p.user_id')->where('p.verification_status', 'verified')->whereNull('p.deleted_at')->where('u.status', 'active')
            ->whereNull('u.deleted_at')->orderBy('p.id')->pluck('p.id');
        $served = 0;

        foreach ($providers as $id) {
            $provider = Provider::find($id);
            $ledger->syncProvider($provider);

            // What the provider could withdraw before any of this seeder's withdrawals: today's available balance, plus what its own withdrawals still hold.
            $own = DB::table('withdrawals')->where('provider_id', $id)->where('idempotency_key', 'like', self::KEY_PREFIX.'%')->whereIn('status', self::HOLDING)->sum('amount');
            $base = round((float) $ledger->balances($provider)['available'] + (float) $own, 2);

            // Enough that even the smallest of the amounts (3% of it) clears the minimum: every provider served gets all six statuses.
            if ($base < max(800.0, $this->smallest() * 40)) {
                continue;
            }

            $served++;
            $this->forProvider($provider, $base, $admins);
        }

        $counts = DB::table('withdrawals')->where('idempotency_key', 'like', self::KEY_PREFIX.'%')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $this->line(sprintf('%d providers: %d new withdrawals (%s in all).', $served, array_sum($this->made), collect($counts)->map(fn ($n, $s) => "{$n} {$s}")->implode(', ')));
    }

    /** The least a demo withdrawal is: the platform's minimum, and never below 20. */
    private function smallest(): float
    {
        return max(20.0, (float) config('wallet.min_withdrawal'));
    }

    /** @param  list<int>  $admins */
    private function forProvider(Provider $provider, float $base, array $admins): void
    {
        $id = $provider->id;
        $this->startRandom((crc32("withdrawals-demo|{$id}") ^ (int) config('seeding.seed')) & 0x7FFFFFFF);

        $bank = $provider->bank_account ?: $this->giveBankAccount($provider);
        $count = $this->rand(8, 14);

        // Every status at least once, then the rest as a real list looks: most are paid.
        $statuses = ['pending', 'processing', 'paid', 'rejected', 'failed', 'cancelled'];
        for ($n = count($statuses); $n < $count; $n++) {
            $statuses[] = $this->weighted(['paid' => 40, 'rejected' => 15, 'failed' => 11, 'cancelled' => 14, 'pending' => 10, 'processing' => 10]);
        }

        // Together the ones that hold money ask for at most 85% of what could be withdrawn, so the balance never goes below zero.
        $room = round($base * 0.85, 2);
        $currency = config('platform.currency');
        $fee = (float) config('wallet.withdrawal_fee_percent');
        $rows = [];
        $entries = [];

        foreach ($statuses as $index => $status) {
            $n = $index + 1;
            $holding = in_array($status, self::HOLDING, true);
            $amount = round($holding ? min($room, $base * $this->rand(4, 18) / 100) : $base * $this->rand(3, 20) / 100, 2);

            if ($amount < $this->smallest() || ($holding && $amount > $room)) {
                continue;
            }
            $room = $holding ? round($room - $amount, 2) : $room;

            [$asked, $handled] = $this->times($status);
            $charge = round($amount * $fee / 100, 2);
            $key = self::KEY_PREFIX."{$id}-{$n}";

            $rows[$key] = [
                'uuid' => $this->uuid('withdrawal-demo', "{$id}-{$n}"), 'provider_id' => $id, 'amount' => $amount, 'fee' => $charge, 'net_amount' => round($amount - $charge, 2),
                'currency' => $currency, 'status' => $status, 'idempotency_key' => $key, 'bank_snapshot' => Crypt::encryptString(json_encode($bank)),
                'payout_reference' => $status === 'paid' ? 'TRF'.str_pad((string) $id, 6, '0', STR_PAD_LEFT).str_pad((string) $n, 2, '0', STR_PAD_LEFT).strtoupper(substr(sha1($key), 0, 4)) : null,
                'status_reason' => isset(self::REASONS[$status]) ? $this->pick(self::REASONS[$status]) : null,
                'processed_by_user_id' => in_array($status, ['pending', 'cancelled'], true) || ! $admins ? null : $this->pick($admins),
                'processed_at' => in_array($status, ['pending', 'cancelled'], true) ? null : $this->ts($handled),
                'created_at' => $this->ts($asked), 'updated_at' => $this->ts($handled ?? $asked),
                '_asked' => $asked, '_handled' => $handled, '_holding' => $holding,
            ];
        }

        $new = array_filter($rows, fn ($row, $key) => ! DB::table('withdrawals')->where('provider_id', $id)->where('idempotency_key', $key)->exists(), ARRAY_FILTER_USE_BOTH);
        $insert = array_map(fn ($row) => array_diff_key($row, array_flip(['_asked', '_handled', '_holding'])), array_values($new));
        $this->made[$id] = $this->insert('withdrawals', $insert);

        $ids = DB::table('withdrawals')->where('provider_id', $id)->whereIn('idempotency_key', array_keys($new))->pluck('id', 'idempotency_key');

        foreach ($new as $key => $row) {
            $wid = $ids[$key];
            $entries[] = $this->entry($id, $wid, WalletEntry::WITHDRAWAL_RESERVE, -$row['amount'], "Withdrawal request #{$wid}", "withdrawal:{$wid}:reserve", $currency, $row['_asked']);

            if (! $row['_holding']) {
                $entries[] = $this->entry($id, $wid, WalletEntry::WITHDRAWAL_RELEASE, $row['amount'], "Withdrawal #{$wid} returned to your balance", "withdrawal:{$wid}:release", $currency, $row['_handled'] ?? $row['_asked']);
            }
        }

        $this->insert('wallet_entries', $entries);
    }

    /**
     * When it was asked for, and when an admin (or the provider, for a cancellation) dealt with it: the ones still waiting are recent, the paid are the oldest. Never later than now.
     *
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private function times(string $status): array
    {
        $asked = match ($status) {
            'pending' => $this->daysAgo(0, 3),
            'processing' => $this->daysAgo(1, 7),
            'paid' => $this->daysAgo(6, 85),
            default => $this->daysAgo(2, 80),
        };
        $asked = $asked->greaterThan($this->now) ? $this->now->copy()->subMinutes(5) : $asked;

        $handled = match ($status) {
            'pending' => null,
            'cancelled' => $asked->copy()->addMinutes($this->rand(10, 600)),
            'processing' => $asked->copy()->addMinutes($this->rand(30, 1440)),
            'paid' => $asked->copy()->addMinutes($this->rand(1440, 5760)),
            default => $asked->copy()->addMinutes($this->rand(600, 4320)),
        };

        return [$asked, $handled ? min($handled, $this->now->copy()) : null];
    }

    /** @return array<string, mixed> */
    private function giveBankAccount(Provider $provider): array
    {
        $bank = ['account_holder_name' => $provider->business_name, 'bank_name' => self::BANKS[$provider->id % count(self::BANKS)], 'iban' => 'SA03'.str_pad((string) $provider->id, 20, '0', STR_PAD_LEFT),
            'swift_code' => null, 'verification_status' => 'approved'];
        $provider->forceFill(['bank_account' => $bank])->save();

        return $bank;
    }

    /** @return array<string, mixed> */
    private function entry(int $provider, int $withdrawal, string $type, float $amount, string $description, string $reference, string $currency, Carbon $at): array
    {
        return ['uuid' => $this->uuid('wallet', $reference), 'provider_id' => $provider, 'reservation_id' => null, 'withdrawal_id' => $withdrawal, 'type' => $type, 'amount' => $amount, 'currency' => $currency,
            'available_at' => null, 'occurred_on' => null, 'description' => $description, 'reference' => $reference, 'meta' => null, 'created_at' => $this->ts($at), 'updated_at' => $this->ts($at)];
    }
}
