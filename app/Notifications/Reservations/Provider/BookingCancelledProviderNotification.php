<?php

namespace App\Notifications\Reservations\Provider;

class BookingCancelledProviderNotification extends BaseReservationProviderNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->providerData(
            'provider.booking.cancelled',
            "A confirmed booking for {$this->reservation->activity->title} on {$this->reservation->reservation_date->toDateString()} was cancelled."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Booking Cancelled',
            'body' => "The booking for {$this->reservation->activity->title} on {$this->reservation->reservation_date->toDateString()} was cancelled.",
            'data' => ['reservation_id' => (string) $this->reservation->id, 'type' => 'provider.booking.cancelled'],
        ];
    }
}
