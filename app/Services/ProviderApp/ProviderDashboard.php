<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use App\Models\User;
use App\Services\NotificationFeed;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** The provider home screen: a summary of bookings, revenue, balances, alerts and reputation. */
class ProviderDashboard
{
    public function __construct(
        private readonly ProviderBookings $bookings,
        private readonly ProviderRevenue $revenue,
        private readonly NotificationFeed $feed,
        private readonly ProviderPerformance $performance,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $user, Provider $provider): array
    {
        $now = Carbon::now();
        $month = $this->revenue->month($provider, $now);

        $reviews = DB::table('reviews as v')->join('activities as a', 'a.id', '=', 'v.activity_id')
            ->where('a.provider_id', $provider->id)->where('v.is_hidden', 0)->selectRaw('COUNT(*) AS total, AVG(v.rating) AS average')->first();

        $inMonth = fn (Carbon $month) => DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->where('a.provider_id', $provider->id)->whereNull('r.deleted_at')->whereIn('r.status', ProviderBookings::REAL)
            ->whereBetween('r.reservation_date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])->count();
        $monthBookings = $inMonth($now);

        // This month so far against the same days of last month (1st to the same day of the month, or the whole of a shorter month): a fair comparison,
        // because revenue only counts trips that have taken place.
        $lastMonth = $now->copy()->subMonthNoOverflow();
        $lastSpanEnd = $lastMonth->copy()->startOfMonth()->addDays(min($now->day, $lastMonth->daysInMonth) - 1);
        $lastSpan = $this->revenue->between($provider, $lastMonth->copy()->startOfMonth()->toDateString(), $lastSpanEnd->toDateString());
        $today = $this->revenue->between($provider, $now->toDateString(), $now->toDateString())['gross'];
        $yesterday = $this->revenue->between($provider, $now->copy()->subDay()->toDateString(), $now->copy()->subDay()->toDateString())['gross'];

        return [
            'business_name' => $provider->business_name,
            'rating' => $reviews->total > 0 ? round((float) $reviews->average, 1) : 0,
            'reviews_count' => (int) $reviews->total,
            'is_verified' => $provider->isVerified(),
            'unread_notifications' => $this->feed->scope($user->unreadNotifications(), 'provider')->count(),
            'month_revenue' => $month + ['change_percent' => ProviderRevenue::changePercent($month['gross'], $lastSpan['gross'])],
            // The last four weeks, oldest first, the current week (so far) last.
            'weekly_revenue' => $this->revenue->weekly($provider, $now),
            'available_balance' => $this->revenue->available($provider),
            'pending_balance' => $this->revenue->pending($provider),
            'month_bookings' => $monthBookings,
            // More (or fewer) bookings than the whole of last month, counted by the day of the trip.
            'month_bookings_change' => $monthBookings - $inMonth($lastMonth),
            'today_revenue' => $today,
            'today_revenue_change_percent' => ProviderRevenue::changePercent($today, $yesterday),
            // Bookings waiting for the provider.
            'needs_action' => $this->bookings->counts($provider)['pending'],
            'todays_bookings' => $this->bookings->today($provider),
            'performance' => $this->performance->summary($provider, $now),
            'rating_distribution' => $this->performance->ratingDistribution($provider),
        ];
    }
}
