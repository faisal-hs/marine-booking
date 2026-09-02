<?php

namespace App\Services;

use App\Repositories\Contracts\AvailabilityOverrideRepositoryInterface;
use App\Repositories\Contracts\ScheduleRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AvailabilityService
{
    public function __construct(
        protected ScheduleRepositoryInterface $scheduleRepository,
        protected AvailabilityOverrideRepositoryInterface $overrideRepository,
    ) {}

    /**
     * Resolves a single date's bookable periods.
     *
     * @return array<int, array{start: string, end: string, next_day: bool, instructions: ?string}>
     */
    public function resolveForDate(int $activityId, Carbon $date): array
    {
        $periods = $this->scheduleRepository->activeForActivity($activityId);
        $overrides = $this->overrideRepository->activeOverlappingRange($activityId, $date, $date);

        return $this->resolveDateFromLoadedData($date, $periods, $overrides);
    }

    /**
     * Resolves every date in [from, to] inclusive in exactly two queries
     * total (periods once, overrides once) — see resolveDateFromLoadedData()
     * for the in-memory per-date logic, which runs with zero further queries.
     *
     * @return array<string, array> keyed by 'Y-m-d'
     */
    public function resolveForRange(int $activityId, Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            throw ValidationException::withMessages([
                'to_date' => ['to_date must be on or after from_date.'],
            ]);
        }

        $periods = $this->scheduleRepository->activeForActivity($activityId);
        $overrides = $this->overrideRepository->activeOverlappingRange($activityId, $from, $to);

        $result = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $result[$cursor->toDateString()] = $this->resolveDateFromLoadedData($cursor, $periods, $overrides);
            $cursor->addDay();
        }

        return $result;
    }

    public function resolveForMonth(int $activityId, int $year, int $month): array
    {
        $from = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $to = $from->copy()->endOfMonth();

        return $this->resolveForRange($activityId, $from, $to);
    }

    /**
     * Module 09 addition — answers a narrower, different question than
     * resolveForDate()'s "list every bookable window": "is THIS specific
     * schedule_period_id (or, if null, the activity in general) bookable
     * on this date at all". Reuses the exact same override/blackout
     * lookup already proven correct elsewhere in this class — purely
     * additive, does not change resolveForDate()/resolveForRange()'s
     * existing behavior or output shape.
     */
    public function isBookable(int $activityId, ?int $schedulePeriodId, Carbon $date): bool
    {
        $overrides = $this->overrideRepository->activeOverlappingRange($activityId, $date, $date);
        $blackout = $overrides->first(fn($o) => $o->is_unavailable && $o->coversDate($date));

        if ($blackout) {
            return false;
        }

        if ($schedulePeriodId === null) {
            return true;
        }

        $period = $this->scheduleRepository->activeForActivity($activityId)->firstWhere('id', $schedulePeriodId);

        return $period !== null && $period->day_of_week === $date->dayOfWeek;
    }

    /**
     * Pure, query-free resolution for one date given already-loaded
     * periods/overrides collections — this is what both resolveForDate()
     * and resolveForRange()'s per-day loop actually call, guaranteeing
     * identical resolution logic (and therefore identical overnight/
     * override behavior) regardless of which entry point is used.
     *
     * Deliberately answers ONLY "is this period available" — never price.
     * Pricing lives entirely in ActivityBookingOption/ActivityPricingRule
     * (Module 08), resolved separately by ActivityPricingService. Mixing
     * pricing into this resolver was the exact anti-pattern Module 08 was
     * built to eliminate.
     */
    protected function resolveDateFromLoadedData(Carbon $date, Collection $periods, Collection $overrides): array
    {
        $blackoutOverride = $overrides->first(fn($o) => $o->is_unavailable && $o->coversDate($date));

        if ($blackoutOverride) {
            return [];
        }

        $instructionsOverride = $overrides->first(fn($o) => ! $o->is_unavailable && $o->coversDate($date));

        $weekday = $date->dayOfWeek; // Carbon: 0 = Sunday ... 6 = Saturday, matching our stored convention.

        $todaysPeriods = $periods->where('day_of_week', $weekday);

        return $todaysPeriods->map(function ($period) use ($date, $instructionsOverride) {
            $start = $date->copy()->setTimeFromTimeString($period->start_time);

            $end = $period->crosses_midnight
                ? $date->copy()->addDay()->setTimeFromTimeString($period->end_time)
                : $date->copy()->setTimeFromTimeString($period->end_time);

            return [
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
                'next_day' => $period->crosses_midnight,
                'instructions' => $instructionsOverride->instructions ?? $period->instructions,
            ];
        })->values()->all();
    }
}
