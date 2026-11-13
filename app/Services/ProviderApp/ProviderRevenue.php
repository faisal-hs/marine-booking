<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use App\Models\WalletEntry;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A provider's revenue, read from the WALLET LEDGER (the financial source of truth), never recomputed from bookings on each request:
 *
 *   GROSS  what customers paid for the provider's COMPLETED bookings, before refunds and commission
 *   NET    what the provider keeps: gross, less what was refunded, less the platform's commission (their own terms: a percentage or a fixed amount)
 *
 * available() is what can be withdrawn now; pending() is what is not withdrawable yet: completed bookings still inside the waiting period
 * (config/wallet.php) plus the expected net of confirmed bookings whose trip has not happened.
 */
class ProviderRevenue
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** @return array{gross: float, net: float} */
    public function month(Provider $provider, \DateTimeInterface $inMonth): array
    {
        $this->ledger->syncProvider($provider);

        $start = Carbon::instance($inMonth)->startOfMonth()->toDateString();
        $end = Carbon::instance($inMonth)->endOfMonth()->toDateString();

        return $this->between($provider, $start, $end);
    }

    /** @return array{gross: float, net: float} for the trips that took place between two dates (inclusive) */
    public function between(Provider $provider, string $from, string $to): array
    {
        $entries = WalletEntry::query()->where('provider_id', $provider->id)->whereIn('type', WalletEntry::BOOKING_TYPES)
            // By DATE: the ledger stores a day as 2026-11-07 00:00:00 on SQLite, so a plain BETWEEN ending on 2026-11-07 would leave that last day out.
            ->whereDate('occurred_on', '>=', $from)->whereDate('occurred_on', '<=', $to);

        return [
            'gross' => round((float) (clone $entries)->where('type', WalletEntry::BOOKING_INCOME)->sum('amount'), 2),
            'net' => round((float) $entries->sum('amount'), 2),
        ];
    }

    /**
     * Gross revenue week by week, oldest first, ending with the current week (so far): $weeks numbers. A week is PLATFORM_WEEK_STARTS_ON to six days later,
     * and a week's revenue is that of the trips that took place in it.
     *
     * @return list<float>
     */
    public function weekly(Provider $provider, \DateTimeInterface $now, int $weeks = 4): array
    {
        $this->ledger->syncProvider($provider);
        $thisWeek = Carbon::instance($now)->startOfWeek((int) config('platform.week_starts_on'));
        $out = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = $thisWeek->copy()->subWeeks($i);
            $out[] = $this->between($provider, $start->toDateString(), $start->copy()->addDays(6)->toDateString())['gross'];
        }

        return $out;
    }

    /** How much more (or less) $now is than $before, in percent (one decimal); null when there was nothing before to compare with. */
    public static function changePercent(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    public function available(Provider $provider): float
    {
        $this->ledger->syncProvider($provider);

        return $this->ledger->balances($provider)['available'];
    }

    public function pending(Provider $provider): float
    {
        $this->ledger->syncProvider($provider);

        return round($this->ledger->balances($provider)['pending'] + $this->expectedFromConfirmed($provider), 2);
    }

    /** The net the provider would keep from bookings that are confirmed (paid) but whose trip has not been completed yet. */
    public function expectedFromConfirmed(Provider $provider): float
    {
        $rows = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->where('a.provider_id', $provider->id)->whereNull('r.deleted_at')->where('r.status', 'confirmed')
            ->selectRaw('r.total, r.service_fee, r.tax, COALESCE((SELECT SUM(p.refunded_amount) FROM payments p WHERE p.reservation_id = r.id), 0) AS refunded')->get();

        $net = 0.0;
        foreach ($rows as $row) {
            // The provider's share of the booking is the price after the coupon (not the service fee or the VAT), and a refund takes back the same share of it.
            $paid = (float) $row->total;
            $base = round($paid - (float) $row->service_fee - (float) $row->tax, 2);
            $kept = max(0, round($base - ($paid > 0 ? (float) $row->refunded * $base / $paid : 0), 2));
            $net += $kept - $provider->commissionOn($kept);
        }

        return round($net, 2);
    }
}
