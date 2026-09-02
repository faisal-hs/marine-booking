<?php

namespace App\Notifications\Reservations;

/**
 * Channel matrix decision: database + fcm only. A newly-created (pending)
 * reservation isn't urgent enough for SMS/email — the customer just
 * created it themselves — but IS time-sensitive (the hold expires),
 * which is exactly what FCM is for per the agreed matrix.
 */
class ReservationCreatedNotification extends BaseReservationNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'reservation.created',
            "Your reservation for {$this->reservation->activity->title} is pending payment."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Reservation Created',
            'body' => "Complete payment for your {$this->reservation->activity->title} booking before the hold expires.",
            'data' => ['reservation_id' => (string) $this->reservation->id, 'type' => 'reservation.created'],
        ];
    }
}
