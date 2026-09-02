<?php

namespace App\Listeners\Notifications;

use App\Events\ReservationExpired;
use App\Models\User;
use App\Notifications\Reservations\Admin\ReservationExpiredAdminNotification;
use App\Notifications\Reservations\ReservationExpiredNotification;
use App\Services\NotificationDispatchService;

class SendReservationExpiredNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ReservationExpired $event): void
    {
        $reservation = $event->reservation;

        $this->dispatcher->dispatchOnce(
            "reservation.expired:{$reservation->id}",
            $reservation->customer->user,
            new ReservationExpiredNotification($reservation)
        );

        foreach ($this->resolveAdminAudience() as $admin) {
            $this->dispatcher->dispatchOnce(
                "admin.reservation.expired:{$reservation->id}",
                $admin,
                new ReservationExpiredAdminNotification($reservation)
            );
        }
    }

    protected function resolveAdminAudience()
    {
        try {
            return User::permission('reservations.view')->get();
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return collect();
        }
    }
}
