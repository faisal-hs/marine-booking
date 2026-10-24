<?php

namespace Tests\Concerns;

use Illuminate\Support\Carbon;

/**
 * Dates for tests, always computed from "today" — never a calendar
 * literal.
 *
 * Why this exists: ReservationService::create() rejects any reservation
 * date in the past, so a test that books for a hardcoded date such as
 * '2026-09-05' passes on the day it is written and starts failing once
 * the real calendar moves past that date. Tests that go through the
 * reservation flow must therefore ask for a date relative to now.
 *
 * Weekday matters only when a specific schedule period is involved:
 * AvailabilityService::isBookable() compares the period's day_of_week
 * with the date, but only when a period id is passed (with null it
 * checks blackouts alone). Most schedule fixtures in this suite are
 * Saturday-only (day_of_week = 6), hence the Saturday/Sunday helpers.
 */
trait RelativeDates
{
    /** The next Saturday after today — matches Saturday-only (day_of_week 6) periods. */
    protected function futureSaturday(): Carbon
    {
        return Carbon::now()->startOfDay()->next(Carbon::SATURDAY);
    }

    /** The day after futureSaturday() — a day a Saturday-only period must NOT match. */
    protected function futureSunday(): Carbon
    {
        return $this->futureSaturday()->addDay();
    }

    /** An arbitrary future date for tests with no weekday dependency. */
    protected function daysFromNow(int $days): Carbon
    {
        return Carbon::now()->startOfDay()->addDays($days);
    }
}
