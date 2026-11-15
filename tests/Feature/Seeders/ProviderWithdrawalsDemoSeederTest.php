<?php

namespace Tests\Feature\Seeders;

use App\Models\Provider;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Database\Seeders\ProviderAnalyticsDemoSeeder;
use Database\Seeders\ProviderWithdrawalsDemoSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\Concerns\UsesSeededDemoDatabase;
use Tests\TestCase;

/**
 * ProviderWithdrawalsDemoSeeder gives the demo providers many withdrawals in every status, with the wallet ledger in the state the withdrawal service leaves it in.
 * Built once for the class: the demo data, the analytics demo data (the bookings that are the earnings), then the withdrawals.
 */
class ProviderWithdrawalsDemoSeederTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use UsesSeededDemoDatabase;

    private const KEY = 'demo-wd-%';

    private const STATUSES = ['pending', 'processing', 'paid', 'rejected', 'failed', 'cancelled'];

    /** A bank account set by hand on one provider before the seeder runs: it must be left as it is. */
    private static ?array $custom = null;

    /** A verified provider who has earned a little, but not enough to be served. */
    private static ?int $small = null;

    protected function seedDemo(): void
    {
        $this->seed(ProviderAnalyticsDemoSeeder::class);

        // The provider with the most completed trips: surely served. Its bank account is already there.
        $id = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('r.status', 'completed')->selectRaw('a.provider_id, COUNT(*) AS n')->groupBy('a.provider_id')->orderByDesc('n')->value('provider_id');
        $bank = ['account_holder_name' => 'Set By Hand', 'bank_name' => 'Hand Made Bank', 'iban' => 'SA0380000000608010167519', 'swift_code' => 'HMBKSARI', 'verification_status' => 'approved'];
        Provider::find($id)->forceFill(['bank_account' => $bank])->save();
        self::$custom = ['provider_id' => $id] + $bank;

        // A verified provider with a completed trip of 300 (270 after the commission): something to withdraw, but under what the seeder needs to serve a provider.
        $small = $this->approvedProvider(['commission_rate' => 10]);
        $this->fundWallet($small, 300, now()->subDays(5)->toDateString());
        self::$small = $small['provider']->id;

        $this->seed(ProviderWithdrawalsDemoSeeder::class);
    }

    private function own()
    {
        return DB::table('withdrawals')->where('idempotency_key', 'like', self::KEY);
    }

    /** @return list<int> providers that have withdrawals from this seeder */
    private function served(): array
    {
        return $this->own()->distinct()->orderBy('provider_id')->pluck('provider_id')->map(fn ($i) => (int) $i)->all();
    }

    private function counts(): array
    {
        return [DB::table('withdrawals')->count(), DB::table('wallet_entries')->count()];
    }

    // --- Who and how many --------------------------------------------------------------------------------------------

    public function test_providers_with_earnings_get_many_withdrawals_and_each_has_all_six_statuses(): void
    {
        $served = $this->served();
        $this->assertGreaterThanOrEqual(3, count($served), 'Several providers have earnings');

        foreach ($served as $id) {
            $byStatus = $this->own()->where('provider_id', $id)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

            $this->assertEqualsCanonicalizing(self::STATUSES, $byStatus->keys()->all(), "Provider {$id} has every status");
            $this->assertGreaterThanOrEqual(8, $byStatus->sum(), "Provider {$id}");
            $this->assertLessThanOrEqual(14, $byStatus->sum(), "Provider {$id}");
        }
    }

    public function test_across_all_providers_most_withdrawals_are_paid_and_the_waiting_ones_are_few(): void
    {
        $byStatus = $this->own()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $this->assertGreaterThan($byStatus['pending'] + $byStatus['processing'], $byStatus['paid']);
        $this->assertGreaterThan(80, $byStatus->sum());
    }

    public function test_a_provider_with_nothing_to_withdraw_gets_nothing_and_nobody_unverified_is_served(): void
    {
        $unverified = DB::table('providers')->where('verification_status', '!=', 'verified')->pluck('id');
        app(LedgerService::class)->syncProvider(Provider::find(self::$small));
        $this->assertGreaterThan(100, app(LedgerService::class)->balances(Provider::find(self::$small))['available'], 'The small provider does have something to withdraw');
        $this->assertSame(0, $this->own()->where('provider_id', self::$small)->count(), 'But too little to be given withdrawals');

        $this->assertSame(0, $this->own()->whereIn('provider_id', $unverified)->count());
        $this->assertGreaterThan(0, DB::table('providers')->where('verification_status', 'verified')->count() - count($this->served()), 'Some verified providers earned too little to be served');
    }

    // --- Each status -------------------------------------------------------------------------------------------------------

    public function test_each_status_carries_what_that_status_means(): void
    {
        $pending = $this->own()->where('status', 'pending');
        $this->assertSame(0, (clone $pending)->where(fn ($q) => $q->whereNotNull('processed_by_user_id')->orWhereNotNull('processed_at')->orWhereNotNull('status_reason')->orWhereNotNull('payout_reference'))->count());

        $processing = $this->own()->where('status', 'processing');
        $this->assertSame(0, (clone $processing)->where(fn ($q) => $q->whereNull('processed_by_user_id')->orWhereNull('processed_at')->orWhereNotNull('payout_reference'))->count());

        $paid = $this->own()->where('status', 'paid');
        $this->assertSame(0, (clone $paid)->where(fn ($q) => $q->whereNull('payout_reference')->orWhereNull('processed_by_user_id')->orWhereNull('processed_at')->orWhereNotNull('status_reason'))->count());

        foreach (['rejected', 'failed'] as $status) {
            $this->assertSame(0, $this->own()->where('status', $status)->where(fn ($q) => $q->whereNull('status_reason')->orWhereNull('processed_by_user_id')->orWhereNull('processed_at')->orWhereNotNull('payout_reference'))->count(), $status);
        }

        $cancelled = $this->own()->where('status', 'cancelled');
        $this->assertSame(0, (clone $cancelled)->where(fn ($q) => $q->whereNull('status_reason')->orWhereNotNull('processed_by_user_id')->orWhereNotNull('payout_reference'))->count(), 'Cancelled by the provider, not an admin');
    }

    public function test_the_transfer_references_are_unique_and_the_admin_who_handled_one_is_an_admin(): void
    {
        $references = $this->own()->whereNotNull('payout_reference')->pluck('payout_reference');
        $this->assertSame($references->count(), $references->unique()->count());
        $this->assertTrue($references->every(fn ($r) => str_starts_with($r, 'TRF')));

        $admins = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereIn('roles.name', ['super_admin', 'admin'])->pluck('model_has_roles.model_id')->all();
        $this->assertSame(0, $this->own()->whereNotNull('processed_by_user_id')->whereNotIn('processed_by_user_id', $admins)->count());
    }

    // --- The money -------------------------------------------------------------------------------------------------------

    public function test_the_net_is_the_amount_less_the_fee_and_every_amount_is_at_least_the_minimum(): void
    {
        $this->assertSame(0, $this->own()->whereRaw('ABS(net_amount - (amount - fee)) > 0.01')->count());
        $this->assertSame(0, $this->own()->where('amount', '<', max(20, (float) config('wallet.min_withdrawal')))->count());
        $this->assertSame(0, $this->own()->where('fee', '<', 0)->count());
    }

    public function test_each_withdrawal_has_a_reserve_entry_and_those_that_gave_the_money_back_have_a_release_entry(): void
    {
        foreach ($this->own()->get() as $w) {
            $reserve = DB::table('wallet_entries')->where('reference', "withdrawal:{$w->id}:reserve")->first();
            $release = DB::table('wallet_entries')->where('reference', "withdrawal:{$w->id}:release")->first();

            $this->assertNotNull($reserve, "Reserve of {$w->id}");
            $this->assertEquals([WalletEntry::WITHDRAWAL_RESERVE, -$w->amount, $w->provider_id, $w->id], [$reserve->type, (float) $reserve->amount, $reserve->provider_id, $reserve->withdrawal_id]);

            if (in_array($w->status, ['rejected', 'failed', 'cancelled'], true)) {
                $this->assertNotNull($release, "Release of {$w->id} ({$w->status})");
                $this->assertEquals([WalletEntry::WITHDRAWAL_RELEASE, (float) $w->amount], [$release->type, (float) $release->amount]);
            } else {
                $this->assertNull($release, "A {$w->status} withdrawal still holds its money");
            }
        }
    }

    public function test_no_balance_goes_below_zero_and_the_ones_that_hold_money_stay_within_what_could_be_withdrawn(): void
    {
        $ledger = app(LedgerService::class);

        foreach ($this->served() as $id) {
            $provider = Provider::find($id);
            $b = $ledger->balances($provider);
            $held = (float) $this->own()->where('provider_id', $id)->whereIn('status', ['pending', 'processing', 'paid'])->sum('amount');

            $this->assertGreaterThanOrEqual(0.0, $b['available'], "Provider {$id}");
            $this->assertLessThanOrEqual(($b['available'] + $held) * 0.85 + 0.01, $held, "Provider {$id}: no more than 85% of what could be withdrawn is held");
            $this->assertEqualsWithDelta((float) $this->own()->where('provider_id', $id)->whereIn('status', ['pending', 'processing'])->sum('amount'), $b['reserved'], 0.01);
            $this->assertEqualsWithDelta((float) $this->own()->where('provider_id', $id)->where('status', 'paid')->sum('amount'), $b['total_withdrawn'], 0.01);
        }
    }

    // --- When ------------------------------------------------------------------------------------------------------------

    public function test_nothing_is_dated_in_the_future_and_the_waiting_ones_are_recent_and_the_paid_are_older(): void
    {
        $now = $this->demoSeededAt()->addHour()->toDateTimeString();

        $this->assertSame(0, DB::table('withdrawals')->where('created_at', '>', $now)->count());
        $this->assertSame(0, $this->own()->whereNotNull('processed_at')->where(fn ($q) => $q->where('processed_at', '>', $now)->orWhereColumn('processed_at', '<', 'created_at'))->count());
        $this->assertSame(0, DB::table('wallet_entries')->where('created_at', '>', $now)->count());
        $this->assertSame(0, $this->own()->where('status', 'pending')->where('created_at', '<', $this->demoSeededAt()->subDays(4)->toDateTimeString())->count(), 'Pending: the last few days');
        $this->assertSame(0, $this->own()->where('status', 'processing')->where('created_at', '<', $this->demoSeededAt()->subDays(8)->toDateTimeString())->count(), 'Processing: the last week');
        $this->assertSame(0, $this->own()->where('status', 'paid')->where('created_at', '>', $this->demoSeededAt()->subDays(5)->toDateTimeString())->count(), 'Paid ones are not the newest');
    }

    // --- The bank account -------------------------------------------------------------------------------------------------

    public function test_a_bank_account_that_was_there_is_left_alone_and_a_provider_without_one_gets_a_demo_one(): void
    {
        $custom = Provider::find(self::$custom['provider_id'])->bank_account;
        $this->assertSame(['Set By Hand', 'SA0380000000608010167519'], [$custom['account_holder_name'], $custom['iban']]);

        foreach (array_diff($this->served(), [self::$custom['provider_id']]) as $id) {
            $account = Provider::find($id)->bank_account;
            $this->assertNotEmpty($account['iban'], "Provider {$id}");
            $this->assertSame('approved', $account['verification_status']);
        }
    }

    public function test_each_withdrawal_keeps_the_account_as_it_was(): void
    {
        $w = $this->own()->where('provider_id', self::$custom['provider_id'])->first();
        $snapshot = json_decode(Crypt::decryptString($w->bank_snapshot), true);

        $this->assertSame(['Set By Hand', 'SA0380000000608010167519'], [$snapshot['account_holder_name'], $snapshot['iban']]);
    }

    public function test_the_idempotency_keys_are_valid_ones_and_unique_for_a_provider(): void
    {
        $this->assertSame(0, $this->own()->whereRaw('LENGTH(idempotency_key) < 8 OR LENGTH(idempotency_key) > 100')->count());
        $this->assertTrue($this->own()->pluck('idempotency_key')->every(fn ($k) => preg_match('/^[A-Za-z0-9._:\-]+$/', $k) === 1));
        $this->assertSame(0, DB::table('withdrawals')->selectRaw('COUNT(*) AS n')->groupBy('provider_id', 'idempotency_key')->having('n', '>', 1)->get()->count());
    }

    // --- What the app shows ------------------------------------------------------------------------------------------------

    public function test_the_provider_app_lists_them_by_status_and_shows_a_paid_ones_reference(): void
    {
        $id = self::$custom['provider_id'];
        $token = $this->tokenFor(User::find(Provider::find($id)->user_id), 'provider');

        $paid = $this->hit($token, 'GET', '/api/v1/provider/withdrawals?status=paid&per_page=100')->assertOk();
        $this->assertSame($this->own()->where('provider_id', $id)->where('status', 'paid')->count(), $paid->json('meta.total'));
        $this->assertSame(['paid'], array_unique(array_column($paid->json('data'), 'status')));

        $failed = $this->hit($token, 'GET', '/api/v1/provider/withdrawals?status=failed&per_page=100')->assertOk();
        $this->assertNotEmpty($failed->json('data.0.failure_reason'));

        $one = $this->hit($token, 'GET', '/api/v1/provider/withdrawals/'.$paid->json('data.0.id'))->assertOk();
        $this->assertStringStartsWith('TRF', $one->json('data.payout_reference'));
    }

    public function test_one_providers_withdrawals_are_not_anothers(): void
    {
        $served = $this->served();
        $other = Provider::find(collect($served)->first(fn ($i) => $i !== self::$custom['provider_id']));
        $token = $this->tokenFor(User::find($other->user_id), 'provider');
        $mine = (int) $this->own()->where('provider_id', self::$custom['provider_id'])->value('id');

        $this->hit($token, 'GET', "/api/v1/provider/withdrawals/{$mine}")->assertNotFound();
        $ids = array_column($this->hit($token, 'GET', '/api/v1/provider/withdrawals?per_page=100')->json('data'), 'id');
        $this->assertEqualsCanonicalizing(DB::table('withdrawals')->where('provider_id', $other->id)->pluck('id')->all(), $ids);
    }

    // --- Running it again ------------------------------------------------------------------------------------------------

    public function test_running_it_again_adds_nothing(): void
    {
        $before = $this->counts();

        $this->seed(ProviderWithdrawalsDemoSeeder::class);
        $this->seed(ProviderWithdrawalsDemoSeeder::class);

        $this->assertSame($before, $this->counts());
    }

    public function test_it_makes_the_same_withdrawals_every_time_the_clock_being_the_same(): void
    {
        $snapshot = fn () => [$this->own()->orderBy('idempotency_key')->get(['idempotency_key', 'provider_id', 'amount', 'fee', 'status', 'created_at', 'processed_at', 'payout_reference', 'status_reason'])->map(fn ($r) => (array) $r)->all(),
            // By the withdrawal's idempotency key: the reference of an entry contains the withdrawal's id, which is new when the row is made again.
            DB::table('wallet_entries as e')->join('withdrawals as w', 'w.id', '=', 'e.withdrawal_id')->orderBy('w.idempotency_key')->orderBy('e.type')->get(['w.idempotency_key', 'e.type', 'e.amount', 'e.created_at'])->map(fn ($r) => (array) $r)->all()];

        // Its dates are placed relative to now, so "the same" means with the same clock: it is pinned for both makings. Everything is done inside a transaction
        // that is rolled back, so the shared data is left as it was.
        Carbon::setTestNow($this->demoSeededAt()->addMinutes(5));
        DB::beginTransaction();
        try {
            $remove = function () {
                DB::table('wallet_entries')->whereIn('withdrawal_id', $this->own()->pluck('id'))->delete();
                $this->own()->delete();
            };
            $remove();
            $this->seed(ProviderWithdrawalsDemoSeeder::class);
            $first = $snapshot();
            $remove();
            $this->seed(ProviderWithdrawalsDemoSeeder::class);
            $again = $snapshot();
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        $this->assertNotEmpty($first[0]);
        $this->assertSame($first, $again);
    }
}
