<?php

namespace App\Notifications\Activities;

use Illuminate\Notifications\Messages\MailMessage;

/** An admin stopped the provider's activity. They are told why, and that only an admin can lift it. */
class ActivitySuspendedNotification extends BaseActivityNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData('activity.suspended', "Your activity \"{$this->activity->title}\" was suspended by an admin.".($this->activity->suspension_reason ? " Reason: {$this->activity->suspension_reason}" : ''));
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Activity Suspended',
            'body' => "\"{$this->activity->title}\" was suspended by an admin and is off the site.",
            'data' => ['activity_id' => (string) $this->activity->id, 'type' => 'activity.suspended'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Activity Was Suspended')
            ->greeting("Hi {$notifiable->name},")
            ->line("An admin suspended \"{$this->activity->title}\", so customers can no longer see or book it.")
            ->when($this->activity->suspension_reason, fn ($mail) => $mail->line("Reason: {$this->activity->suspension_reason}"))
            ->line('You cannot switch it back on yourself: contact the platform team, and an admin will lift the suspension once it is resolved.');
    }
}
