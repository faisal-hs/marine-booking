<?php

namespace App\Listeners\Notifications;

use App\Events\ReservationCreated;
use App\Models\User;
use App\Notifications\Reservations\Admin\NewReservationAdminNotification;
use App\Notifications\Reservations\ReservationCreatedNotification;
use App\Services\NotificationDispatchService;

class SendReservationCreatedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ReservationCreated $event): void
    {
        $reservation = $event->reservation;

        $this->dispatcher->dispatchOnce(
            "reservation.created:{$reservation->id}",
            $reservation->customer->user,
            new ReservationCreatedNotification($reservation)
        );

        // Admin audience resolved by permission, not by role name — matches
        // whoever CAN currently view reservations, regardless of which
        // specific role grants it, per the existing reservations.view
        // permission already established in Module 09.
        //
        // Deliberately defensive: Spatie's permission() scope THROWS if the
        // permission record doesn't exist yet (e.g. a fresh environment
        // where migrations ran but the permission seeder hasn't) — that
        // must never be allowed to crash the actual reservation flow just
        // because an admin-side notification lookup failed.
        foreach ($this->resolveAdminAudience() as $admin) {
            $this->dispatcher->dispatchOnce(
                "admin.reservation.created:{$reservation->id}",
                $admin,
                new NewReservationAdminNotification($reservation)
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
