<?php

namespace App\Notifications\Activities;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Channel matrix decision: database + fcm + mail. A rejection needs to
 * explain WHY (the rejection_reason), which genuinely warrants a formal
 * email a provider can refer back to while making corrections.
 */
class ActivityRejectedNotification extends BaseActivityNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'activity.rejected',
            "Your activity \"{$this->activity->title}\" was not approved."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Activity Not Approved',
            'body' => "\"{$this->activity->title}\" was not approved. Check your email for details.",
            'data' => ['activity_id' => (string) $this->activity->id, 'type' => 'activity.rejected'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Activity Was Not Approved')
            ->greeting("Hi {$notifiable->name},")
            ->line("Unfortunately, \"{$this->activity->title}\" was not approved.")
            ->when($this->activity->rejection_reason, fn($mail) => $mail->line("Reason: {$this->activity->rejection_reason}"))
            ->line('You can make the necessary changes and resubmit for review.');
    }
}
