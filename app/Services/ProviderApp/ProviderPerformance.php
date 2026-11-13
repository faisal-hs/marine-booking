<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How a provider is doing, as the dashboard shows it. Each figure has one definition, and a figure with nothing to be worked out from is null (not 0).
 *
 *   booking_rate        of the bookings made in the last 30 days, the percentage that were kept: paid and confirmed, or completed
 *                       (the rest were cancelled, never paid and expired, or are still waiting for their payment)
 *   cancellation_rate   of the same bookings, the percentage that were cancelled (by the customer or by the provider)
 *   response_rate       of the reviews written in the last 30 days, the percentage the provider replied to
 *   repeat_customers    of the customers who have a kept booking with the provider, the percentage who have two or more
 *   rating_distribution the share of the visible reviews that gave each star, in percent, adding up to exactly 100 (every star is listed)
 */
class ProviderPerformance
{
    public const DAYS = 30;

    public function __construct(private readonly ProviderAnalytics $analytics) {}

    /** @return array{booking_rate: ?float, response_rate: ?float, cancellation_rate: ?float, repeat_customers: ?float} */
    public function summary(Provider $provider, Carbon $now): array
    {
        $since = $now->copy()->subDays(self::DAYS);
        $made = fn () => DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $provider->id)->whereNull('r.deleted_at')->where('r.created_at', '>=', $since);
        $total = $made()->count();

        return [
            'booking_rate' => $total > 0 ? round($made()->whereIn('r.status', ['confirmed', 'completed'])->count() / $total * 100, 1) : null,
            'response_rate' => $this->analytics->responseRate($provider, null, $since->copy()->startOfDay(), $now->copy()->endOfDay()),
            'cancellation_rate' => $total > 0 ? round($made()->where('r.status', 'cancelled')->count() / $total * 100, 1) : null,
            'repeat_customers' => $this->repeatCustomers($provider),
        ];
    }

    private function repeatCustomers(Provider $provider): ?float
    {
        $perCustomer = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $provider->id)->whereNull('r.deleted_at')
            ->whereIn('r.status', ['confirmed', 'completed'])->groupBy('r.customer_id')->selectRaw('COUNT(*) AS n')->pluck('n');

        return $perCustomer->isEmpty() ? null : round($perCustomer->filter(fn ($n) => $n >= 2)->count() / $perCustomer->count() * 100, 1);
    }

    /** @return array<string, int> */
    public function ratingDistribution(Provider $provider): array
    {
        $counts = DB::table('reviews as v')->join('activities as a', 'a.id', '=', 'v.activity_id')->where('a.provider_id', $provider->id)->where('v.is_hidden', 0)
            ->selectRaw('v.rating AS star, COUNT(*) AS total')->groupBy('v.rating')->pluck('total', 'star');
        $total = (int) $counts->sum();
        $shares = [];

        foreach ([5, 4, 3, 2, 1] as $star) {
            $exact = $total > 0 ? ($counts[$star] ?? 0) / $total * 100 : 0;
            $shares[$star] = ['floor' => (int) floor($exact), 'rest' => $exact - floor($exact)];
        }

        // The largest remainders get the points the rounding down left over, so the five always add up to exactly 100 (or are all 0 with no reviews).
        $left = $total > 0 ? 100 - array_sum(array_column($shares, 'floor')) : 0;
        $order = array_keys($shares);
        usort($order, fn ($a, $b) => $shares[$b]['rest'] <=> $shares[$a]['rest'] ?: $b <=> $a);
        foreach (array_slice($order, 0, $left) as $star) {
            $shares[$star]['floor']++;
        }

        return collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($star) => [(string) $star => $shares[$star]['floor']])->all();
    }
}
