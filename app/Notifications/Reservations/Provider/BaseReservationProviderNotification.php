<?php

namespace App\Notifications\Reservations\Provider;

use App\Notifications\Reservations\BaseReservationNotification;

/**
 * Notifications that tell an activity's PROVIDER about a booking.
 *
 * They carry only what the provider's own reservation list already shows
 * (activity, date, party, total). No customer name or contact is included: the
 * provider API exposes none today, and an alert must not reveal more than the
 * screen it points to.
 */
abstract class BaseReservationProviderNotification extends BaseReservationNotification
{
    protected function providerData(string $type, string $message): array
    {
        return array_merge($this->baseData($type, $message), [
            'reservation_date' => $this->reservation->reservation_date->toDateString(),
            'participants' => $this->reservation->participants,
            'quantity' => $this->reservation->quantity,
        ]);
    }

    /** In-app and push only: the provider has the full detail in the app, so no SMS or e-mail. */
    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }
}
