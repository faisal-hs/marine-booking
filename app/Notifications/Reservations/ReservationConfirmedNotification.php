<?php

namespace App\Notifications\Reservations;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Channel matrix decision: database + fcm + sms + mail — the fullest
 * matrix of any reservation event. This is the one moment that matters
 * most to a customer (a real, paid booking is locked in), justifying
 * SMS as a reliable backup channel and a formal email confirmation,
 * matching the agreed "SMS only for critical events with real business
 * value" and "email for formal/important confirmations" guidance.
 */
class ReservationConfirmedNotification extends BaseReservationNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'sms', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'reservation.confirmed',
            "Your reservation for {$this->reservation->activity->title} is confirmed."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Reservation Confirmed',
            'body' => "Your {$this->reservation->activity->title} booking on {$this->reservation->reservation_date->toDateString()} is confirmed.",
            'data' => ['reservation_id' => (string) $this->reservation->id, 'type' => 'reservation.confirmed'],
        ];
    }

    public function toSms(object $notifiable): string
    {
        return "Marine Booking: Your reservation for {$this->reservation->activity->title} on {$this->reservation->reservation_date->toDateString()} is confirmed. Total: {$this->reservation->total} {$this->reservation->currency}.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Reservation is Confirmed')
            ->greeting("Hi {$notifiable->name},")
            ->line("Your reservation for {$this->reservation->activity->title} is confirmed.")
            ->line("Date: {$this->reservation->reservation_date->toDateString()}")
            ->line("Total paid: {$this->reservation->total} {$this->reservation->currency}")
            ->line('Thank you for booking with us!');
    }
}
