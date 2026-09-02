<?php

namespace App\Notifications\Reservations\Admin;

class ReservationExpiredAdminNotification extends BaseReservationAdminNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'admin.reservation.expired',
            "Reservation for {$this->reservation->activity->title} by {$this->reservation->customer->user->name} expired unpaid."
        );
    }
}
