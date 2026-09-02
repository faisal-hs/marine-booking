<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pending Reservation Hold Duration
    |--------------------------------------------------------------------------
    |
    | How long a 'pending' reservation temporarily consumes capacity while
    | waiting for payment confirmation. Once hold_expires_at passes, the
    | reservation stops counting against capacity immediately in every
    | live capacity query — this setting does NOT depend on a scheduled
    | job having run to take effect (see ReservationRepository::consumedCapacity()).
    | A scheduled job still exists to flip stale rows to 'expired' for
    | reporting/data-hygiene purposes, but it is never load-bearing for
    | correctness.
    |
    */
    'hold_minutes' => (int) env('RESERVATION_HOLD_MINUTES', 15),
];
