<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** What the provider's earnings screens show: balances, revenue, the breakdown by activity, the ledger as transactions, the monthly statement and the annual report. All from the wallet ledger. */
class ProviderEarnings
{
    /** The spec's names for the kinds of ledger row. */
    private const TRANSACTION_TYPES = [
        WalletEntry::BOOKING_INCOME => 'income', WalletEntry::PLATFORM_FEE => 'fee', WalletEntry::REFUND => 'refund',
        WalletEntry::WITHDRAWAL_RESERVE => 'payout', WalletEntry::WITHDRAWAL_RELEASE => 'payout', WalletEntry::ADJUSTMENT => 'adjustment',
    ];

    public function __construct(private readonly LedgerService $ledger) {}

    /** @return array<string, mixed> */
    public function summary(Provider $provider, int $transactions = 50): array
    {
        $this->ledger->syncProvider($provider);
        $balances = $this->ledger->balances($provider);
        $booking = fn () => WalletEntry::query()->where('provider_id', $provider->id)->whereIn('type', WalletEntry::BOOKING_TYPES);
        $last = Withdrawal::where('provider_id', $provider->id)->where('status', Withdrawal::PAID)->orderByDesc('processed_at')->orderByDesc('id')->first();

        return [
            'currency' => config('platform.currency'),
            'available_balance' => $balances['available'],
            'pending_balance' => $balances['pending'],
            'reserved_balance' => $balances['reserved'],
            'total_earnings' => $balances['total_earnings'],
            'total_withdrawn' => $balances['total_withdrawn'],
            'gross_revenue' => round((float) $booking()->where('type', WalletEntry::BOOKING_INCOME)->sum('amount'), 2),
            'net_revenue' => round((float) $booking()->sum('amount'), 2),
            'last_payout' => $last ? ['date' => $last->processed_at->utc()->format('Y-m-d\TH:i:s\Z'), 'amount' => $last->amount] : null,
            'revenue_breakdown' => $this->breakdown($provider),
            'transactions' => WalletEntry::query()->where('provider_id', $provider->id)->orderByDesc('id')->limit($transactions)->get()
                ->map(fn (WalletEntry $e) => ['id' => $e->id, 'title' => $e->description, 'amount' => $e->amount, 'type' => self::TRANSACTION_TYPES[$e->type] ?? $e->type])->all(),
        ];
    }

    /** @return list<array{activity: string, gross: float, net: float}> */
    private function breakdown(Provider $provider): array
    {
        return DB::table('wallet_entries as w')->join('reservations as r', 'r.id', '=', 'w.reservation_id')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->where('w.provider_id', $provider->id)->whereIn('w.type', WalletEntry::BOOKING_TYPES)
            ->groupBy('a.id', 'a.title')->orderByRaw("SUM(CASE WHEN w.type = 'booking_income' THEN w.amount ELSE 0 END) DESC")
            ->selectRaw("a.title AS activity, SUM(CASE WHEN w.type = 'booking_income' THEN w.amount ELSE 0 END) AS gross, SUM(w.amount) AS net")->get()
            ->map(fn ($row) => ['activity' => $row->activity, 'gross' => round((float) $row->gross, 2), 'net' => round((float) $row->net, 2)])->all();
    }

    /** @return array<string, mixed> */
    public function report(Provider $provider, int $year): array
    {
        $this->ledger->syncProvider($provider);

        $perMonth = fn (int $y) => WalletEntry::query()->where('provider_id', $provider->id)->whereIn('type', WalletEntry::BOOKING_TYPES)
            ->whereBetween('occurred_on', ["{$y}-01-01", "{$y}-12-31"])->get(['type', 'amount', 'occurred_on', 'reservation_id']);

        $entries = $perMonth($year);
        $monthly = [];
        foreach (range(1, 12) as $month) {
            $of = $entries->filter(fn ($e) => (int) $e->occurred_on->format('n') === $month);
            $monthly[] = ['month' => $month, 'gross' => round((float) $of->where('type', WalletEntry::BOOKING_INCOME)->sum('amount'), 2), 'net' => round((float) $of->sum('amount'), 2)];
        }

        $gross = round((float) $entries->where('type', WalletEntry::BOOKING_INCOME)->sum('amount'), 2);
        $net = round((float) $entries->sum('amount'), 2);
        $before = round((float) $perMonth($year - 1)->sum('amount'), 2);
        $trips = $entries->where('type', WalletEntry::BOOKING_INCOME)->pluck('reservation_id')->unique()->count();

        return [
            'year' => $year,
            'currency' => config('platform.currency'),
            'gross_total' => $gross,
            'net_total' => $net,
            // Net against the year before; null when there was nothing to compare with.
            'change_percent' => $before > 0 ? round(($net - $before) / $before * 100, 1) : null,
            'monthly' => $monthly,
            'pending_payout' => $this->ledger->balances($provider)['reserved'],
            'completed_trips' => $trips,
            'avg_per_booking' => $trips > 0 ? round($gross / $trips, 2) : 0,
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> the rows of one month's statement (the header is first) */
    public function statementRows(Provider $provider, string $month): array
    {
        $this->ledger->syncProvider($provider);

        $first = Carbon::createFromFormat('!Y-m', $month);
        $start = $first->copy()->startOfMonth();
        $end = $first->copy()->endOfMonth();

        $entries = WalletEntry::query()->where('provider_id', $provider->id)
            ->where(fn ($q) => $q
                ->where(fn ($b) => $b->whereIn('type', WalletEntry::BOOKING_TYPES)->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()]))
                ->orWhere(fn ($w) => $w->whereNotIn('type', WalletEntry::BOOKING_TYPES)->whereBetween('created_at', [$start, $end])))
            ->orderByRaw('COALESCE(occurred_on, DATE(created_at))')->orderBy('id')->get();

        $rows = [['Date', 'Description', 'Type', 'Amount ('.config('platform.currency').')']];
        foreach ($entries as $e) {
            $rows[] = [($e->occurred_on ?? $e->created_at)->toDateString(), $e->description, self::TRANSACTION_TYPES[$e->type] ?? $e->type, number_format($e->amount, 2, '.', '')];
        }
        $rows[] = ['', 'Total', '', number_format($entries->sum('amount'), 2, '.', '')];

        return $rows;
    }
}
