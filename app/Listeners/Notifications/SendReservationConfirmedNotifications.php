<?php

namespace App\Listeners\Notifications;

use App\Events\ReservationConfirmed;
use App\Notifications\Reservations\ReservationConfirmedNotification;
use App\Services\NotificationDispatchService;

/**
 * Customer only — deliberately no admin notification for a routine
 * successful confirmation, per "do NOT simply send every customer
 * notification to admins" (a confirmed booking doesn't require admin
 * action or monitoring, unlike a new/cancelled/expired one).
 */
class SendReservationConfirmedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ReservationConfirmed $event): void
    {
        $reservation = $event->reservation;

        $this->dispatcher->dispatchOnce(
            "reservation.confirmed:{$reservation->id}",
            $reservation->customer->user,
            new ReservationConfirmedNotification($reservation)
        );
    }
}
