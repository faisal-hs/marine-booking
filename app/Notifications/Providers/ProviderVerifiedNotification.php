<?php

namespace App\Notifications\Providers;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Channel matrix decision: database + fcm + mail. Being verified is a
 * significant, formal milestone for a provider (it likely gates whether
 * they can publish activities at all), warranting an email record,
 * matching the "email for formal/important... verification
 * notifications" guidance.
 */
class ProviderVerifiedNotification extends BaseProviderNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'provider.verified',
            'Your provider account has been verified.'
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Account Verified',
            'body' => 'Your provider account has been verified. You can now start listing activities.',
            'data' => ['provider_id' => (string) $this->provider->id, 'type' => 'provider.verified'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Provider Account Has Been Verified')
            ->greeting("Hi {$notifiable->name},")
            ->line("Congratulations — your provider account for \"{$this->provider->business_name}\" has been verified.")
            ->line('You can now start listing activities for customers to book.');
    }
}
