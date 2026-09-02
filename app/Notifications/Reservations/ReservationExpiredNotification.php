<?php

namespace App\Notifications\Reservations;

/**
 * Channel matrix decision: database + fcm only. A routine, expected
 * outcome (they simply didn't pay in time) — not significant enough to
 * warrant email or SMS, matching the "don't send everything through
 * every channel" guidance.
 */
class ReservationExpiredNotification extends BaseReservationNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'reservation.expired',
            "Your reservation hold for {$this->reservation->activity->title} has expired."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Reservation Expired',
            'body' => "Your hold for {$this->reservation->activity->title} expired without payment. You can book again anytime.",
            'data' => ['reservation_id' => (string) $this->reservation->id, 'type' => 'reservation.expired'],
        ];
    }
}
