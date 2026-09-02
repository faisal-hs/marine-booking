<?php

namespace App\Notifications\Reservations\Admin;

class ReservationCancelledAdminNotification extends BaseReservationAdminNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'admin.reservation.cancelled',
            "Reservation for {$this->reservation->activity->title} by {$this->reservation->customer->user->name} was cancelled."
        );
    }
}
