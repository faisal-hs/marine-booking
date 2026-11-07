<?php

namespace App\Notifications\Reservations;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Channel matrix decision: database + fcm + mail. A cancellation
 * deserves a formal email record, but not SMS — the customer already
 * knows (they usually initiated it), so SMS wouldn't add real business
 * value here per the agreed "SMS only where it provides real business
 * value" guidance.
 */
class ReservationCancelledNotification extends BaseReservationNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'reservation.cancelled',
            $this->byProvider()
                ? "{$this->providerName()} cancelled your reservation for {$this->reservation->activity->title}. Your payment is being refunded in full."
                : "Your reservation for {$this->reservation->activity->title} has been cancelled."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Reservation Cancelled',
            'body' => $this->byProvider()
                ? "{$this->providerName()} cancelled your {$this->reservation->activity->title} booking. You are being refunded in full."
                : "Your {$this->reservation->activity->title} booking has been cancelled.",
            'data' => ['reservation_id' => (string) $this->reservation->id, 'type' => 'reservation.cancelled'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reservation Cancelled')
            ->greeting("Hi {$notifiable->name},")
            ->line($this->byProvider()
                ? "{$this->providerName()} cancelled your reservation for {$this->reservation->activity->title} on {$this->reservation->reservation_date->toDateString()}."
                : "Your reservation for {$this->reservation->activity->title} on {$this->reservation->reservation_date->toDateString()} has been cancelled.")
            ->when($this->byProvider(), fn ($mail) => $mail->line('Your payment is being refunded in full.'))
            ->when($this->reservation->cancellation_reason, fn($mail) => $mail->line("Reason: {$this->reservation->cancellation_reason}"));
    }

    /** Cancelled by the provider, not by the customer. */
    private function byProvider(): bool
    {
        return $this->reservation->cancellation_source === \App\Enums\CancellationSource::Provider;
    }

    private function providerName(): string
    {
        return $this->reservation->activity->provider->business_name;
    }
}
