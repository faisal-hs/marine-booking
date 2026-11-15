<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use App\Models\WalletEntry;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * The figures that describe a provider as a whole, for the whole of their life on the platform. They are the same ones the dashboard and the activity
 * list work out (a rating is the average of the visible reviews; revenue is the wallet ledger's gross: the price of the trips that took place, after the
 * provider's own coupon, never the service fee or VAT), so they agree wherever they appear. A provider with nothing yet gets zeros, and a rating of 0.
 *
 *   activities_count, active_activities_count   every activity, and the live ones
 *   bookings_count                              confirmed and completed bookings
 *   completed_bookings_count                    the completed ones
 *   upcoming_bookings_count                     the confirmed ones whose trip is still to come
 *   customers_count                             how many different customers have a confirmed or completed booking
 *   rating, reviews_count                       the average (one decimal) and the number of visible reviews
 *   gross_revenue                               the ledger's gross, all time
 *   available_balance, pending_balance          what can be withdrawn now, and what is still waiting for its trip day to pass
 */
class ProviderStats
{
    public function __construct(
        private readonly ActivityRepositoryInterface $activities,
        private readonly LedgerService $ledger,
    ) {}

    /** @return array<string, mixed> */
    public function for(Provider $provider): array
    {
        $this->ledger->syncProvider($provider);

        $counts = $this->activities->countsForProvider($provider->id);
        $bookings = fn () => DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $provider->id)->whereNull('r.deleted_at');
        $kept = fn () => $bookings()->whereIn('r.status', ['confirmed', 'completed']);
        $reviews = DB::table('reviews as v')->join('activities as a', 'a.id', '=', 'v.activity_id')->where('a.provider_id', $provider->id)->where('v.is_hidden', 0)
            ->selectRaw('COUNT(*) AS total, AVG(v.rating) AS average')->first();
        $balances = $this->ledger->balances($provider);

        return [
            'activities_count' => $counts['all'],
            'active_activities_count' => $counts['active'],
            'bookings_count' => $kept()->count(),
            'completed_bookings_count' => $bookings()->where('r.status', 'completed')->count(),
            'upcoming_bookings_count' => $bookings()->where('r.status', 'confirmed')->where('r.reservation_date', '>=', now()->toDateString())->count(),
            'customers_count' => (int) $kept()->distinct()->count('r.customer_id'),
            'rating' => $reviews->total > 0 ? round((float) $reviews->average, 1) : 0,
            'reviews_count' => (int) $reviews->total,
            'gross_revenue' => round((float) WalletEntry::query()->where('provider_id', $provider->id)->where('type', WalletEntry::BOOKING_INCOME)->sum('amount'), 2),
            'available_balance' => $balances['available'],
            'pending_balance' => $balances['pending'],
        ];
    }
}
