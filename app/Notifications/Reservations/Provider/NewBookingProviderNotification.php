<?php

namespace App\Notifications\Reservations\Provider;

class NewBookingProviderNotification extends BaseReservationProviderNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->providerData(
            'provider.booking.confirmed',
            "New confirmed booking for {$this->reservation->activity->title} on {$this->reservation->reservation_date->toDateString()}."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New Booking',
            'body' => "{$this->reservation->activity->title} is booked for {$this->reservation->reservation_date->toDateString()}.",
            'data' => ['reservation_id' => (string) $this->reservation->id, 'type' => 'provider.booking.confirmed'],
        ];
    }
}
