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
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $user, Provider $provider): array
    {
        $now = Carbon::now();
        $month = $this->revenue->month($provider, $now);

        $reviews = DB::table('reviews as v')->join('activities as a', 'a.id', '=', 'v.activity_id')
            ->where('a.provider_id', $provider->id)->where('v.is_hidden', 0)->selectRaw('COUNT(*) AS total, AVG(v.rating) AS average')->first();

        $monthBookings = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->where('a.provider_id', $provider->id)->whereNull('r.deleted_at')->whereIn('r.status', ProviderBookings::REAL)
            ->whereBetween('r.reservation_date', [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()])->count();

        return [
            'business_name' => $provider->business_name,
            'rating' => $reviews->total > 0 ? round((float) $reviews->average, 1) : 0,
            'reviews_count' => (int) $reviews->total,
            'is_verified' => $provider->isVerified(),
            'unread_notifications' => $this->feed->scope($user->unreadNotifications(), 'provider')->count(),
            'month_revenue' => $month,
            'available_balance' => $this->revenue->available($provider),
            'pending_balance' => $this->revenue->pending($provider),
            'month_bookings' => $monthBookings,
            // Bookings waiting for the provider.
            'needs_action' => $this->bookings->counts($provider)['pending'],
            'todays_bookings' => $this->bookings->today($provider),
        ];
    }
}
