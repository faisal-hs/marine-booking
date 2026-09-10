<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;

/**
 * Snapshot statistics only — current totals/counts for the admin
 * overview page. Deliberately NOT time-series/trend data or filterable
 * reports; that is Module 15 (Reporting)'s job. The one exception,
 * "net revenue in the last 30 days", is included because it is a common,
 * simple at-a-glance dashboard figure, not a full report.
 */
class DashboardService
{
    public function getStats(): array
    {
        return [
            'users' => $this->userStats(),
            'providers' => $this->providerStats(),
            'activities' => $this->activityStats(),
            'reservations' => $this->reservationStats(),
            'payments' => $this->paymentStats(),
            'reviews' => $this->reviewStats(),
        ];
    }

    protected function userStats(): array
    {
        return [
            'total' => User::count(),
            'customers' => User::where('user_type', 'customer')->count(),
            'providers' => User::where('user_type', 'provider')->count(),
        ];
    }

    protected function providerStats(): array
    {
        return [
            'pending_verification' => Provider::where('verification_status', 'pending')->count(),
            'verified' => Provider::where('verification_status', 'verified')->count(),
            'rejected' => Provider::where('verification_status', 'rejected')->count(),
        ];
    }

    protected function activityStats(): array
    {
        return [
            'published' => Activity::where('status', 'published')->count(),
            'pending_review' => Activity::where('status', 'pending_review')->count(),
        ];
    }

    protected function reservationStats(): array
    {
        return [
            'total' => Reservation::count(),
            'pending' => Reservation::where('status', 'pending')->count(),
            'confirmed' => Reservation::where('status', 'confirmed')->count(),
            'today' => Reservation::where('status', 'confirmed')
                ->whereDate('reservation_date', now()->toDateString())
                ->count(),
            'upcoming_7_days' => Reservation::where('status', 'confirmed')
                ->whereBetween('reservation_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                ->count(),
        ];
    }

    /**
     * Net revenue (all-time) = gross amount of every payment that ever
     * succeeded, minus whatever was refunded — this correctly nets out
     * BOTH partial and full refunds uniformly, without needing separate
     * branches for "succeeded" vs "partially_refunded" vs "refunded"
     * statuses (a fully-refunded payment nets to exactly 0 through this
     * formula alone).
     *
     * The 30-day figure is deliberately GROSS, not net — there is no
     * dedicated refunded_at timestamp on Payment, only refunded_amount
     * and updated_at (which can be touched for unrelated reasons), so
     * attributing a refund to a specific 30-day window isn't reliably
     * possible with the current schema. Labeled accordingly rather than
     * presented as a precise net figure it isn't.
     */
    protected function paymentStats(): array
    {
        $succeededStatuses = ['succeeded', 'partially_refunded', 'refunded'];

        $grossRevenue = (float) Payment::whereIn('status', $succeededStatuses)->sum('amount');
        $totalRefunded = (float) Payment::sum('refunded_amount');

        $grossRevenueLast30Days = (float) Payment::whereIn('status', $succeededStatuses)
            ->where('paid_at', '>=', now()->subDays(30))
            ->sum('amount');

        return [
            'net_revenue' => round($grossRevenue - $totalRefunded, 2),
            'gross_revenue_last_30_days' => round($grossRevenueLast30Days, 2),
            'pending_count' => Payment::where('status', 'pending')->count(),
            'failed_recent_count' => Payment::where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'total_refunded' => round($totalRefunded, 2),
        ];
    }

    protected function reviewStats(): array
    {
        return [
            'total' => Review::where('is_hidden', false)->count(),
            'average_platform_rating' => round((float) (Review::where('is_hidden', false)->avg('rating') ?? 0), 2),
        ];
    }

    public function recentReservations(int $limit = 5)
    {
        return Reservation::with(['activity', 'customer.user'])
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function recentPayments(int $limit = 5)
    {
        return Payment::with(['reservation.activity', 'reservation.customer.user'])
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function recentUsers(int $limit = 5)
    {
        return User::latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
