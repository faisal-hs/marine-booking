<?php

namespace App\Notifications\Reservations;

use App\Models\Reservation;
use App\Notifications\Concerns\QueuedDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class BaseReservationNotification extends Notification implements ShouldQueue
{
    use QueuedDelivery;

    public function __construct(protected Reservation $reservation) {}

    protected function baseData(string $type, string $message): array
    {
        return [
            'type' => $type,
            'reservation_id' => $this->reservation->id,
            'reservation_uuid' => $this->reservation->uuid,
            'activity_title' => $this->reservation->activity->title,
            'total' => (float) $this->reservation->total,
            'currency' => $this->reservation->currency,
            'message' => $message,
        ];
    }
}
