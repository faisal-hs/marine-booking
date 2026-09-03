<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

/**
 * The prerequisite Module 12 needs for "verified purchase" reviews — see
 * ReservationService::completePastReservations()'s own doc comment for
 * the exact completion rule and its known simplification.
 */
class CompletePastReservations extends Command
{
    protected $signature = 'reservations:complete-past';

    protected $description = 'Marks confirmed reservations as completed once their reservation date has passed.';

    public function handle(ReservationService $service): int
    {
        $count = $service->completePastReservations();

        $this->info("Completed {$count} past reservation(s).");

        return self::SUCCESS;
    }
}
