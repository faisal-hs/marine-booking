<?php

namespace App\Events;

use App\Models\Reservation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Deliberately carries ONLY the domain fact "this happened to this
 * reservation" — zero knowledge of notifications, channels, or
 * recipients. ReservationService fires this and moves on; everything
 * about WHO gets notified and HOW lives entirely in the Listener.
 */
class ReservationConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Reservation $reservation) {}
}
