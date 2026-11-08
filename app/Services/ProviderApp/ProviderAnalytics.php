<?php

namespace App\Services\ProviderApp;

use App\Models\ActivityView;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\WalletEntry;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A provider's analytics for a period (default: the last 30 days), optionally for one activity. Every figure has ONE definition:
 *
 *   page_views         one visitor looking at one of the activities on one day (a refresh adds nothing; the provider's own looks do not count)
 *   conversion_rate    bookings made in the period (confirmed or completed: a cancelled or expired one is not a booking) divided by page views
 *                      in the period, times 100; 0 when nobody looked
 *   *_change           against the period of the same length just before: page views as a percentage, conversion in percentage points;
 *                      null when the earlier period had no page views to compare with
 *   avg_rating         the average of all the visible reviews (an admin-hidden one is not counted), null when there are none
 *   response_rate      of the visible reviews WRITTEN in the period, the percentage the provider replied to; null when there were none
 *   top_activities     the activities whose trips took place in the period, best gross first, from the WALLET LEDGER (completed bookings only):
 *                      so the revenue here is the revenue everywhere else: gross is what customers paid (after any coupon), net is what the provider keeps
 *   booking_sources    the share of those bookings that came from each source (direct, category, recommendations, social), adding up to 100
 */
class ProviderAnalytics
{
    private const COUNTED = ['confirmed', 'completed'];

    public function __construct(private readonly LedgerService $ledger) {}

    /** @return array<string, mixed> */
    public function report(Provider $provider, ?int $activityId, Carbon $from, Carbon $to): array
    {
        $this->ledger->syncProvider($provider);

        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);

        [$views, $bookings] = [$this->views($provider, $activityId, $from, $to), $this->bookings($provider, $activityId, $from, $to)->count()];
        [$previousViews, $previousBookings] = [$this->views($provider, $activityId, $previousFrom, $previousTo), $this->bookings($provider, $activityId, $previousFrom, $previousTo)->count()];

        $conversion = $this->rate($bookings, $views);
        $previousConversion = $this->rate($previousBookings, $previousViews);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'page_views' => $views,
            'page_views_change' => $previousViews > 0 ? round(($views - $previousViews) / $previousViews * 100, 1) : null,
            'conversion_rate' => $conversion,
            'conversion_change' => $previousViews > 0 ? round($conversion - $previousConversion, 1) : null,
            'avg_rating' => $this->averageRating($provider, $activityId),
            'response_rate' => $this->responseRate($provider, $activityId, $from, $to),
            'top_activities' => $this->topActivities($provider, $activityId, $from, $to),
            'booking_sources' => $this->sources($provider, $activityId, $from, $to),
        ];
    }

    private function views(Provider $provider, ?int $activityId, Carbon $from, Carbon $to): int
    {
        return ActivityView::query()->whereIn('activity_id', $this->activityIds($provider, $activityId))
            ->whereBetween('viewed_on', [$from->toDateString(), $to->toDateString()])->count();
    }

    private function bookings(Provider $provider, ?int $activityId, Carbon $from, Carbon $to): Builder
    {
        return Reservation::query()->whereIn('activity_id', $this->activityIds($provider, $activityId))->whereIn('status', self::COUNTED)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    private function activityIds(Provider $provider, ?int $activityId)
    {
        return DB::table('activities')->where('provider_id', $provider->id)->when($activityId, fn ($q) => $q->where('id', $activityId))->select('id');
    }

    private function rate(int $bookings, int $views): float
    {
        return $views > 0 ? round($bookings / $views * 100, 1) : 0.0;
    }

    private function reviews(Provider $provider, ?int $activityId): Builder
    {
        return Review::query()->where('is_hidden', false)->whereIn('activity_id', $this->activityIds($provider, $activityId));
    }

    private function averageRating(Provider $provider, ?int $activityId): ?float
    {
        $average = $this->reviews($provider, $activityId)->avg('rating');

        return $average === null ? null : round((float) $average, 2);
    }

    private function responseRate(Provider $provider, ?int $activityId, Carbon $from, Carbon $to): ?float
    {
        $written = $this->reviews($provider, $activityId)->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        $total = (clone $written)->count();

        if ($total === 0) {
            return null;
        }

        return round((clone $written)->whereHas('reply')->count() / $total * 100, 1);
    }

    /** @return list<array{id: int, title: string, bookings: int, gross_revenue: float, net_revenue: float}> */
    private function topActivities(Provider $provider, ?int $activityId, Carbon $from, Carbon $to): array
    {
        return DB::table('wallet_entries as w')->join('reservations as r', 'r.id', '=', 'w.reservation_id')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->where('w.provider_id', $provider->id)->whereIn('w.type', WalletEntry::BOOKING_TYPES)
            ->whereBetween('w.occurred_on', [$from->toDateString(), $to->toDateString()])->when($activityId, fn ($q) => $q->where('a.id', $activityId))
            ->groupBy('a.id', 'a.title')->orderByRaw("SUM(CASE WHEN w.type = 'booking_income' THEN w.amount ELSE 0 END) DESC")->orderBy('a.id')->limit(5)
            ->selectRaw("a.id, a.title, COUNT(DISTINCT CASE WHEN w.type = 'booking_income' THEN w.reservation_id END) AS bookings, SUM(CASE WHEN w.type = 'booking_income' THEN w.amount ELSE 0 END) AS gross, SUM(w.amount) AS net")
            ->get()->map(fn ($row) => ['id' => (int) $row->id, 'title' => $row->title, 'bookings' => (int) $row->bookings, 'gross_revenue' => round((float) $row->gross, 2), 'net_revenue' => round((float) $row->net, 2)])->all();
    }

    /** @return list<array{source: string, percent: int}> the four sources in a fixed order, adding up to 100 (or all 0 when there were no bookings) */
    private function sources(Provider $provider, ?int $activityId, Carbon $from, Carbon $to): array
    {
        $counts = $this->bookings($provider, $activityId, $from, $to)->selectRaw("COALESCE(source, 'direct') AS source, COUNT(*) AS total")->groupBy('source')->pluck('total', 'source');
        $total = (int) $counts->sum();
        $shares = [];
        foreach (ActivityView::SOURCES as $source) {
            $exact = $total > 0 ? ($counts[$source] ?? 0) / $total * 100 : 0;
            $shares[$source] = ['floor' => (int) floor($exact), 'rest' => $exact - floor($exact)];
        }

        // The largest remainders get the points the rounding down left over, so the four always add up to exactly 100.
        $left = $total > 0 ? 100 - array_sum(array_column($shares, 'floor')) : 0;
        $order = array_keys($shares);
        usort($order, fn ($a, $b) => $shares[$b]['rest'] <=> $shares[$a]['rest'] ?: array_search($a, ActivityView::SOURCES) <=> array_search($b, ActivityView::SOURCES));
        foreach (array_slice($order, 0, $left) as $source) {
            $shares[$source]['floor']++;
        }

        return array_map(fn ($source) => ['source' => $source, 'percent' => $shares[$source]['floor']], ActivityView::SOURCES);
    }
}
