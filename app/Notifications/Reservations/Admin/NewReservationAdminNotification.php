<?php

namespace App\Notifications\Reservations\Admin;

class NewReservationAdminNotification extends BaseReservationAdminNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'admin.reservation.created',
            "New reservation from {$this->reservation->customer->user->name} for {$this->reservation->activity->title}."
        );
    }
}
