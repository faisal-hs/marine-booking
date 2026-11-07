<?php

namespace App\Notifications\Providers;

use App\Models\ProviderProfileChange;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a provider what the admin decided about their profile change. */
class ProviderProfileChangeReviewedNotification extends Notification
{
    public function __construct(protected ProviderProfileChange $change) {}

    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    private function approved(): bool
    {
        return $this->change->status === ProviderProfileChange::APPROVED;
    }

    private function fields(): string
    {
        return implode(', ', str_replace('_', ' ', $this->change->fields()));
    }

    private function sentence(): string
    {
        return $this->approved()
            ? "Your change to the {$this->fields()} was approved and is now in effect."
            : "Your change to the {$this->fields()} was not approved.".($this->change->rejection_reason ? " Reason: {$this->change->rejection_reason}" : '');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->approved() ? 'provider.profile_change.approved' : 'provider.profile_change.rejected',
            'provider_profile_change_uuid' => $this->change->uuid,
            'message' => $this->sentence(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->approved() ? 'Profile change approved' : 'Profile change not approved',
            'body' => $this->sentence(),
            'data' => ['type' => $this->approved() ? 'provider.profile_change.approved' : 'provider.profile_change.rejected'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->approved() ? 'Your profile change was approved' : 'Your profile change was not approved')
            ->greeting("Hi {$notifiable->name},")
            ->line($this->sentence());
    }
}
