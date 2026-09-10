<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Distinct from DashboardService (Module 13): that class answers "what
 * does the platform look like right now" (snapshot counts/totals with
 * no date filtering). This class answers "how has the platform
 * performed over a chosen period" — trend charts and top-performer
 * breakdowns, always scoped to an explicit [from, to] range.
 *
 * Revenue figures throughout are deliberately GROSS (successful payment
 * amounts), not net of refunds — Payment has no dedicated refunded_at
 * timestamp (only refunded_amount and updated_at, which can be touched
 * for unrelated reasons), so attributing a refund to a specific date or
 * period isn't reliably possible with the current schema. This is the
 * same disclosed limitation DashboardService's paymentStats() already
 * carries for its own 30-day figure.
 */
class ReportService
{
    protected const SUCCEEDED_STATUSES = ['succeeded', 'partially_refunded', 'refunded'];

    public function revenueOverTime(Carbon $from, Carbon $to): array
    {
        $rows = Payment::selectRaw('DATE(paid_at) as day, SUM(amount) as total')
            ->whereIn('status', self::SUCCEEDED_STATUSES)
            ->whereBetween('paid_at', [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        return $this->fillDateSeries($from, $to, $rows, 'total');
    }

    /**
     * Counts every reservation created in the period regardless of
     * status — this is a demand/booking-activity trend (when did people
     * attempt to book), distinct from DashboardService's own
     * "confirmed reservations today/upcoming" snapshot, which is
     * forward-looking rather than historical.
     */
    public function reservationsOverTime(Carbon $from, Carbon $to): array
    {
        $rows = Reservation::selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        return $this->fillDateSeries($from, $to, $rows, 'total');
    }

    /**
     * Zero-fills days with no data — a chart with gaps silently skipped
     * would misleadingly compress the x-axis; this guarantees one point
     * per calendar day across the full requested range.
     */
    protected function fillDateSeries(Carbon $from, Carbon $to, Collection $rows, string $valueColumn): array
    {
        $labels = [];
        $data = [];

        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $labels[] = $key;
            $data[] = isset($rows[$key]) ? (float) $rows[$key]->{$valueColumn} : 0;
            $cursor->addDay();
        }

        return ['labels' => $labels, 'data' => $data];
    }

    public function topActivities(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        return Activity::query()
            ->select('activities.id', 'activities.title', 'activities.uuid')
            ->selectRaw('COUNT(DISTINCT payments.id) as bookings_count')
            ->selectRaw('COALESCE(SUM(payments.amount), 0) as revenue')
            ->join('reservations', 'reservations.activity_id', '=', 'activities.id')
            ->join('payments', 'payments.reservation_id', '=', 'reservations.id')
            ->whereIn('payments.status', self::SUCCEEDED_STATUSES)
            ->whereBetween('payments.paid_at', [$from, $to])
            ->groupBy('activities.id', 'activities.title', 'activities.uuid')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();
    }

    public function topProviders(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        return Provider::query()
            ->select('providers.id', 'providers.business_name')
            ->selectRaw('COUNT(DISTINCT payments.id) as bookings_count')
            ->selectRaw('COALESCE(SUM(payments.amount), 0) as revenue')
            ->join('activities', 'activities.provider_id', '=', 'providers.id')
            ->join('reservations', 'reservations.activity_id', '=', 'activities.id')
            ->join('payments', 'payments.reservation_id', '=', 'reservations.id')
            ->whereIn('payments.status', self::SUCCEEDED_STATUSES)
            ->whereBetween('payments.paid_at', [$from, $to])
            ->groupBy('providers.id', 'providers.business_name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();
    }

    public function topCategories(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        return Category::query()
            ->select('categories.id', 'categories.name')
            ->selectRaw('COUNT(DISTINCT payments.id) as bookings_count')
            ->selectRaw('COALESCE(SUM(payments.amount), 0) as revenue')
            ->join('activities', 'activities.category_id', '=', 'categories.id')
            ->join('reservations', 'reservations.activity_id', '=', 'activities.id')
            ->join('payments', 'payments.reservation_id', '=', 'reservations.id')
            ->whereIn('payments.status', self::SUCCEEDED_STATUSES)
            ->whereBetween('payments.paid_at', [$from, $to])
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();
    }
}
