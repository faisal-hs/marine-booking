<?php

namespace App\Notifications\Providers;

use Illuminate\Notifications\Messages\MailMessage;

class ProviderRejectedNotification extends BaseProviderNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'provider.rejected',
            'Your provider verification was not approved.'
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Verification Not Approved',
            'body' => 'Your provider verification was not approved. Check your email for details.',
            'data' => ['provider_id' => (string) $this->provider->id, 'type' => 'provider.rejected'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Provider Verification Was Not Approved')
            ->greeting("Hi {$notifiable->name},")
            ->line("Unfortunately, verification for \"{$this->provider->business_name}\" was not approved.")
            ->when($this->provider->rejection_reason, fn($mail) => $mail->line("Reason: {$this->provider->rejection_reason}"))
            ->line('You can update your details and reapply.');
    }
}
