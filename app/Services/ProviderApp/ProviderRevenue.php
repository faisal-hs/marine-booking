<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Gross and net revenue of a provider, as the spec defines them:
 *
 *   GROSS  the total value of COMPLETED bookings, before the platform's commission.
 *   NET    what the provider keeps: (gross - what was refunded on those bookings) x (1 - commission rate).
 *
 * The commission is the provider's own commission_rate (a percentage an admin sets on the provider). Refunds are treated as borne by the
 * provider, which is the one reading of "provider-borne adjustments/refunds" the data supports today; it is one of the decisions the spec
 * leaves open (see docs/PROVIDER_API.md).
 *
 * available() and pending() are PROVISIONAL balances until the wallet ledger exists (spec phase 4): available is the net of every completed
 * booking, pending the net of bookings confirmed but not yet completed. Nothing has been withdrawn yet, because there is no withdrawal yet.
 */
class ProviderRevenue
{
    /** @return array{gross: float, net: float} */
    public function month(Provider $provider, \DateTimeInterface $inMonth): array
    {
        $start = Carbon::instance($inMonth)->startOfMonth()->toDateString();
        $end = Carbon::instance($inMonth)->endOfMonth()->toDateString();

        return $this->figures($provider, 'completed', fn (Builder $q) => $q->whereBetween('r.reservation_date', [$start, $end]));
    }

    public function available(Provider $provider): float
    {
        return $this->figures($provider, 'completed')['net'];
    }

    public function pending(Provider $provider): float
    {
        return $this->figures($provider, 'confirmed')['net'];
    }

    /** @return array{gross: float, net: float} */
    private function figures(Provider $provider, string $status, ?callable $scope = null): array
    {
        $reservations = function () use ($provider, $status, $scope): Builder {
            $query = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')
                ->where('a.provider_id', $provider->id)->whereNull('r.deleted_at')->where('r.status', $status);

            return $scope ? $scope($query) : $query;
        };

        $gross = (float) $reservations()->sum('r.total');
        $refunded = (float) DB::table('payments')->whereIn('reservation_id', $reservations()->select('r.id'))->sum('refunded_amount');
        $rate = (float) $provider->commission_rate;

        return [
            'gross' => round($gross, 2),
            'net' => round(max(0, $gross - $refunded) * (1 - $rate / 100), 2),
        ];
    }
}
