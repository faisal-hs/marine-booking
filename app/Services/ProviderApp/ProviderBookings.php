<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The provider's bookings (the app's name for reservations), always scoped to ONE provider through that provider's activities.
 *
 * What the tabs mean:
 *   today     the trips of today that are waiting or confirmed (or done): pending, confirmed, completed
 *   upcoming  trips after today still to be served: pending, confirmed
 *   pending   waiting for action, any day
 */
class ProviderBookings
{
    public const STATUSES = ['pending', 'confirmed', 'cancelled', 'expired', 'completed'];

    /** Statuses of a booking that is real: not cancelled, not expired. */
    public const REAL = ['pending', 'confirmed', 'completed'];

    public const TO_SERVE = ['pending', 'confirmed'];

    public function query(Provider $provider): Builder
    {
        return DB::table('reservations as r')
            ->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('activity_schedule_periods as s', 's.id', '=', 'r.schedule_period_id')
            ->where('a.provider_id', $provider->id)
            ->whereNull('r.deleted_at')
            ->select('r.id', 'r.status', 'r.reservation_date', 'r.participants', 'r.quantity', 'r.total', 'a.title as activity', 'u.name as customer_name', 's.start_time');
    }

    /** @param  array{filter?: string, status?: string, from?: string, to?: string}  $filters */
    public function paginate(Provider $provider, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $today = Carbon::now()->toDateString();
        $query = $this->query($provider);

        match ($filters['filter'] ?? 'all') {
            'today' => $query->where('r.reservation_date', $today)->whereIn('r.status', self::REAL)->orderBy('s.start_time')->orderBy('r.id'),
            'upcoming' => $query->where('r.reservation_date', '>', $today)->whereIn('r.status', self::TO_SERVE)->orderBy('r.reservation_date')->orderBy('s.start_time')->orderBy('r.id'),
            'pending' => $query->where('r.status', 'pending')->orderBy('r.reservation_date')->orderBy('r.id'),
            default => $query->orderByDesc('r.reservation_date')->orderByDesc('r.id'),
        };

        if (! empty($filters['status'])) {
            $query->where('r.status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->where('r.reservation_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('r.reservation_date', '<=', $filters['to']);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return array{today: int, upcoming: int, pending: int} */
    public function counts(Provider $provider): array
    {
        $today = Carbon::now()->toDateString();
        $count = fn () => DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $provider->id)->whereNull('r.deleted_at');

        return [
            'today' => $count()->where('r.reservation_date', $today)->whereIn('r.status', self::REAL)->count(),
            'upcoming' => $count()->where('r.reservation_date', '>', $today)->whereIn('r.status', self::TO_SERVE)->count(),
            'pending' => $count()->where('r.status', 'pending')->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function today(Provider $provider, int $limit = 50): array
    {
        return $this->query($provider)->where('r.reservation_date', Carbon::now()->toDateString())->whereIn('r.status', self::REAL)
            ->orderBy('s.start_time')->orderBy('r.id')->limit($limit)->get()->map(fn ($row) => $this->format($row))->all();
    }

    /** @return array{id: int, customer_name: string, activity: string, date_time: string, guests: int, amount: float, status: string} */
    public function format(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'customer_name' => $row->customer_name,
            'activity' => $row->activity,
            // The trip's day and its departure time, as an instant (the app's timezone is the platform's).
            'date_time' => Carbon::parse(substr((string) $row->reservation_date, 0, 10).' '.($row->start_time ?: '00:00'))->utc()->format('Y-m-d\TH:i:s\Z'),
            'guests' => $this->guests($row),
            'amount' => round((float) $row->total, 2),
            'status' => $row->status,
        ];
    }

    /** People on the trip: the sum of the participants of a per-person booking, otherwise the quantity (a trip or a group counts as one). */
    private function guests(object $row): int
    {
        $participants = is_string($row->participants) ? json_decode($row->participants, true) : $row->participants;

        if (is_array($participants) && $participants !== []) {
            return (int) array_sum($participants);
        }

        return (int) ($row->quantity ?: 1);
    }
}
