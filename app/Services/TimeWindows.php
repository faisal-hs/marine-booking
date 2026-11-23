<?php

namespace App\Services;

use App\Models\ActivityBookingOption;
use App\Models\ActivitySchedulePeriod;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A booking by TIME is a WINDOW inside an operating period (a departure): from one moment to another. This knows where a period starts and ends (a period that crosses midnight, 20:00
 * to 05:00, is ONE period that ends the next day), which operating day a moment belongs to, which windows are taken, and whether a new one is free.
 *
 * Windows of one booking option never overlap, wherever they are: it is checked against everything the option has booked, not only the same departure, so two departures that touch (or an
 * overnight one running into the next day) cannot both hold the same minute. They may touch: one ending at 11:00 and the next starting at 11:00 are fine. A booking holds its time when it is
 * confirmed, or pending with a hold that has not run out (as for capacity); a cancelled, expired or refused one holds nothing. A booking by the hour made without a window (a number of hours)
 * holds its departure from its start for that long.
 */
class TimeWindows
{
    public function __construct(private readonly ReservationRepositoryInterface $reservations) {}

    /** The platform's timezone: the times of a period, and of a window, are the ones people on the spot read. */
    public static function tz(): string
    {
        return (string) config('platform.timezone');
    }

    /** @return array{start: Carbon, end: Carbon} where a period starts and ends on its operating day (a period that crosses midnight ends the next day) */
    public function bounds(ActivitySchedulePeriod $period, Carbon $day): array
    {
        $date = $day->toDateString();
        $start = Carbon::parse($date.' '.substr((string) $period->start_time, 0, 5), self::tz());
        $end = Carbon::parse($date.' '.substr((string) $period->end_time, 0, 5), self::tz());

        return ['start' => $start, 'end' => $period->crosses_midnight || $end->lte($start) ? $end->addDay() : $end];
    }

    /**
     * The OPERATING DAY a moment belongs to for a period: the date the period STARTS. A moment in the part of an overnight period after midnight (02:00, for a period from 20:00 to 05:00)
     * belongs to the day before. For a period that stays inside one day it is the date of the moment.
     */
    public function operatingDay(ActivitySchedulePeriod $period, Carbon $moment): Carbon
    {
        $local = $moment->copy()->setTimezone(self::tz());
        $overnight = $period->crosses_midnight || substr((string) $period->end_time, 0, 5) <= substr((string) $period->start_time, 0, 5);
        $afterMidnight = $overnight && $local->format('H:i') < substr((string) $period->start_time, 0, 5);

        return Carbon::parse($local->toDateString())->startOfDay()->subDays($afterMidnight ? 1 : 0);
    }

    /** @return list<array{from: Carbon, to: Carbon}> the windows taken within this period on this operating day, in the platform's timezone, earliest first */
    public function busy(ActivityBookingOption $option, ActivitySchedulePeriod $period, Carbon $day): array
    {
        $bounds = $this->bounds($period, $day);

        return $this->taken($option, $bounds['start'], $bounds['end']);
    }

    /** The first taken window that the window from `$from` to `$to` runs into, or null when it is free. */
    public function conflict(ActivityBookingOption $option, Carbon $from, Carbon $to): ?array
    {
        return $this->taken($option, $from, $to)[0] ?? null;
    }

    /** Runs INSIDE the reservation's transaction, right after the option's lock is taken (the lock is what makes what it reads fresh): two bookings cannot both take the same time. */
    public function assertFree(ActivityBookingOption $option, Carbon $from, Carbon $to): void
    {
        if ($this->conflict($option, $from, $to)) {
            throw ValidationException::withMessages(['window' => ['This time is already booked.']]);
        }
    }

    /**
     * Every window of the option that overlaps `$from` to `$to`: those with a window of their own, and those made as a number of hours.
     *
     * @return list<array{from: Carbon, to: Carbon}>
     */
    private function taken(ActivityBookingOption $option, Carbon $from, Carbon $to): array
    {
        $tz = self::tz();
        $windows = [];

        foreach ($this->reservations->windowsOverlapping($option->id, $from, $to) as $r) {
            $windows[] = ['from' => $r->starts_at->copy()->setTimezone($tz), 'to' => $r->ends_at->copy()->setTimezone($tz)];
        }

        // A booking made as a number of hours holds its period from the start. Those of the days around are looked at: a period that crosses midnight starts the day before.
        foreach ($this->reservations->holdsWithoutWindow($option->id, $from->copy()->subDays(2)->toDateString(), $to->copy()->addDay()->toDateString()) as $r) {
            $period = ActivitySchedulePeriod::withTrashed()->find($r->schedule_period_id);

            if (! $period) {
                continue;
            }

            $start = $this->bounds($period, Carbon::parse($r->reservation_date->toDateString()))['start'];
            $hold = ['from' => $start, 'to' => $start->copy()->addHours((int) $r->duration)];

            if ($from->lt($hold['to']) && $to->gt($hold['from'])) {
                $windows[] = $hold;
            }
        }

        usort($windows, fn (array $a, array $b) => $a['from']->getTimestamp() <=> $b['from']->getTimestamp());

        return $windows;
    }
}
