<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The people who have booked with ONE provider, with a tier. Only customers of that provider are ever returned, and only what is needed to
 * serve them (name, history): no phone number, no email.
 *
 * A customer is someone with at least one confirmed or completed booking. The score and tiers are configured in config/provider_app.php.
 */
class ProviderCustomers
{
    /**
     * @param  array{tier?: string, q?: string}  $filters
     * @return array{summary: array{total: int, vip: int, new_this_month: int}, customers: list<array<string, mixed>>, page: LengthAwarePaginator}
     */
    public function list(Provider $provider, array $filters, int $perPage, int $page): array
    {
        $all = $this->all($provider, $filters['q'] ?? null);

        // The summary describes all the provider's customers, so the tabs keep their counts whatever is filtered.
        $everyone = isset($filters['q']) && $filters['q'] !== '' ? $this->all($provider, null) : $all;
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $summary = [
            'total' => $everyone->count(),
            'vip' => $everyone->where('tier', 'vip')->count(),
            'new_this_month' => $everyone->filter(fn (array $c) => $c['first_booking'] >= $monthStart)->count(),
        ];

        $matching = empty($filters['tier']) ? $all : $all->where('tier', $filters['tier']);
        $matching = $matching->sortBy([['vip_score', 'desc'], ['name', 'asc'], ['id', 'asc']])->values();

        $paginator = new LengthAwarePaginator($matching->forPage($page, $perPage)->values()->all(), $matching->count(), $perPage, $page);

        return [
            'summary' => $summary,
            'customers' => collect($paginator->items())->map(fn (array $c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'bookings_count' => $c['bookings_count'],
                'total_spent' => $c['total_spent'],
                'last_booking' => Carbon::parse($c['last_booking'])->utc()->format('Y-m-d\TH:i:s\Z'),
                'tier' => $c['tier'],
                'vip_score' => $c['vip_score'],
            ])->all(),
            'page' => $paginator,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function all(Provider $provider, ?string $search)
    {
        $config = config('provider_app.customer_tiers');
        $recentSince = Carbon::now()->subDays($config['recent_days'])->toDateTimeString();
        $cancelledSince = Carbon::now()->subDays($config['cancellation_days'])->toDateTimeString();

        $query = DB::table('reservations as r')
            ->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('a.provider_id', $provider->id)->whereNull('r.deleted_at')
            ->groupBy('c.id', 'u.name')
            ->havingRaw("SUM(CASE WHEN r.status IN ('confirmed', 'completed') THEN 1 ELSE 0 END) > 0")
            ->selectRaw("c.id AS id, u.name AS name,
                SUM(CASE WHEN r.status IN ('confirmed', 'completed') THEN 1 ELSE 0 END) AS bookings_count,
                SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN r.status = 'completed' THEN r.total - r.service_fee - r.tax ELSE 0 END) AS total_spent,
                SUM(CASE WHEN r.status IN ('confirmed', 'completed') AND r.created_at >= ? THEN 1 ELSE 0 END) AS recent_count,
                SUM(CASE WHEN r.status = 'cancelled' AND r.cancelled_at >= ? THEN 1 ELSE 0 END) AS recent_cancellations,
                MAX(CASE WHEN r.status IN ('confirmed', 'completed') THEN r.reservation_date END) AS last_booking,
                MIN(CASE WHEN r.status IN ('confirmed', 'completed') THEN r.reservation_date END) AS first_booking", [$recentSince, $cancelledSince]);

        if ($search !== null && $search !== '') {
            // '!' escapes a literal %, _ or ! in what the person typed (a backslash would be an escape character in MySQL itself).
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($q) => $q->whereRaw("u.name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("u.phone LIKE ? ESCAPE '!'", [$like]));
        }

        return $query->get()->map(function ($row) use ($config) {
            $score = $this->score($row, $config);

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'bookings_count' => (int) $row->bookings_count,
                'total_spent' => round((float) $row->total_spent, 2),
                'last_booking' => substr((string) $row->last_booking, 0, 10),
                'first_booking' => substr((string) $row->first_booking, 0, 10),
                'vip_score' => $score,
                'tier' => $score >= $config['thresholds']['vip'] ? 'vip' : ($score >= $config['thresholds']['regular'] ? 'regular' : 'normal'),
            ];
        });
    }

    /** @param  array<string, mixed>  $config */
    private function score(object $row, array $config): int
    {
        $w = $config['weights'];
        $cap = $config['caps'];
        $earn = fn (float $value, float $cap, int $weight) => min($weight, $weight * $value / max(1, $cap));

        return (int) round(
            $earn((float) $row->total_spent, $cap['spend'], $w['spend'])
            + $earn((float) $row->completed_count, $cap['bookings'], $w['bookings'])
            + $earn((float) $row->recent_count, $cap['recent'], $w['recent'])
            + ((int) $row->recent_cancellations === 0 ? $w['no_cancellations'] : 0)
        );
    }
}
