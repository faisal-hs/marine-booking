<?php

namespace App\Listeners\Notifications;

use App\Events\ReservationCancelled;
use App\Models\User;
use App\Notifications\Reservations\Admin\ReservationCancelledAdminNotification;
use App\Notifications\Reservations\Provider\BookingCancelledProviderNotification;
use App\Notifications\Reservations\ReservationCancelledNotification;
use App\Services\NotificationDispatchService;

class SendReservationCancelledNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ReservationCancelled $event): void
    {
        $reservation = $event->reservation;

        $this->dispatcher->dispatchOnce(
            "reservation.cancelled:{$reservation->id}",
            $reservation->customer->user,
            new ReservationCancelledNotification($reservation)
        );

        // Only a booking the provider was told about (a confirmed one) is worth telling
        // them was cancelled; a pending hold that is cancelled never reached them.
        if ($reservation->confirmed_at !== null) {
            $this->dispatcher->dispatchOnce(
                "provider.reservation.cancelled:{$reservation->id}",
                $reservation->activity->provider->user,
                new BookingCancelledProviderNotification($reservation)
            );
        }

        foreach ($this->resolveAdminAudience() as $admin) {
            $this->dispatcher->dispatchOnce(
                "admin.reservation.cancelled:{$reservation->id}",
                $admin,
                new ReservationCancelledAdminNotification($reservation)
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
