<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

/**
 * Data-hygiene only, NOT load-bearing for correctness. The live capacity
 * check already ignores an expired pending hold immediately, with or
 * without this command having run — see
 * ReservationRepository::consumedCapacity(). This just keeps stored
 * statuses honest for reporting/admin visibility.
 */
class ExpireStaleReservationHolds extends Command
{
    protected $signature = 'reservations:expire-stale-holds';

    protected $description = 'Flips pending reservations whose hold has expired to expired status (data hygiene only — capacity checks are already live-correct without this).';

    public function handle(ReservationService $service): int
    {
        $count = $service->expireStaleHolds();

        $this->info("Expired {$count} stale reservation hold(s).");

        return self::SUCCESS;
    }
}
