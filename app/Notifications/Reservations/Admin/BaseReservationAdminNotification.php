<?php

namespace App\Notifications\Reservations\Admin;

use App\Models\Reservation;
use Illuminate\Notifications\Notification;

abstract class BaseReservationAdminNotification extends Notification
{
    public function __construct(protected Reservation $reservation) {}

    protected function baseData(string $type, string $message): array
    {
        return [
            'type' => $type,
            'reservation_id' => $this->reservation->id,
            'reservation_uuid' => $this->reservation->uuid,
            'activity_title' => $this->reservation->activity->title,
            'customer_name' => $this->reservation->customer->user->name,
            'message' => $message,
        ];
    }

    /**
     * Deliberately database-only for every admin reservation event per
     * the agreed "keep admin notifications primarily in-app initially"
     * guidance — none of these three events (new reservation, cancelled,
     * expired) are urgent enough to justify FCM/SMS/email for admin
     * staff, unlike their customer-facing counterparts.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }
}
