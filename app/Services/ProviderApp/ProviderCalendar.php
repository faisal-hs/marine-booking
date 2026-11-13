<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One month of a provider's calendar: the days that have bookings and the days that are blocked.
 *
 * A day is BLOCKED when every published activity of the provider is closed that day by an active "unavailable" override. A provider with no
 * published activity has no blocked days. (When provider-wide blocked dates exist, spec phase 2 availability, they feed this too.)
 * Only days with bookings or blocked are listed, in date order.
 */
class ProviderCalendar
{
    /** @return list<array{date: string, bookings_count: int, is_blocked: bool}> */
    public function month(Provider $provider, string $month): array
    {
        $first = Carbon::createFromFormat('!Y-m', $month);
        $start = $first->copy()->startOfMonth()->toDateString();
        $end = $first->copy()->endOfMonth()->toDateString();

        $bookings = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->where('a.provider_id', $provider->id)->whereNull('r.deleted_at')->whereIn('r.status', ProviderBookings::REAL)
            ->whereBetween('r.reservation_date', [$start, $end])
            ->groupBy('r.reservation_date')->selectRaw('r.reservation_date AS day, COUNT(*) AS total')->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (int) $total]);

        $blocked = $this->blockedDays($provider, $start, $end);

        $days = [];
        for ($day = $first->copy()->startOfMonth(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $date = $day->toDateString();
            $count = $bookings[$date] ?? 0;

            // Every day of the month, from the 1st to the last, whether or not anything happens on it.
            $days[] = ['date' => $date, 'bookings_count' => $count, 'is_blocked' => isset($blocked[$date])];
        }

        return $days;
    }

    /** @return array<string, true> */
    private function blockedDays(Provider $provider, string $start, string $end): array
    {
        $activities = DB::table('activities')->where('provider_id', $provider->id)->where('status', 'published')->whereNull('deleted_at')->pluck('id');

        if ($activities->isEmpty()) {
            return [];
        }

        $overrides = DB::table('activity_availability_overrides')->whereIn('activity_id', $activities)->where('status', 'active')->where('is_unavailable', 1)
            ->where('date_from', '<=', $end)->where('date_to', '>=', $start)->get(['activity_id', 'date_from', 'date_to']);

        $closed = [];
        foreach ($overrides as $override) {
            $from = Carbon::parse($override->date_from)->max(Carbon::parse($start));
            $to = Carbon::parse($override->date_to)->min(Carbon::parse($end));

            for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
                $closed[$day->toDateString()][$override->activity_id] = true;
            }
        }

        $blocked = [];
        foreach ($closed as $date => $byActivity) {
            if (count($byActivity) === $activities->count()) {
                $blocked[$date] = true;
            }
        }

        return $blocked;
    }
}
