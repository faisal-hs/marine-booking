<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Data-hygiene only — see ExpireStaleReservationHolds's own doc comment.
// Runs every 5 minutes since a stale hold otherwise sits visibly
// "pending" in reporting/admin views for however long between runs,
// even though it's already correctly ignored by live capacity checks.
Schedule::command('reservations:expire-stale-holds')->everyFiveMinutes();

// Runs daily — unlike expire-stale-holds, this genuinely IS load-bearing:
// Module 12's "verified purchase" review requirement depends entirely on
// reservations actually reaching 'completed' status. See
// ReservationService::completePastReservations()'s own doc comment.
Schedule::command('reservations:complete-past')->daily();
