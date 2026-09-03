<?php

namespace App\Notifications\Activities;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Channel matrix decision: database + fcm + mail. In this codebase,
 * approve() sets an activity straight to 'published' in one action —
 * this notification covers that combined "approved and now live"
 * moment, formal/important enough for a provider that email is
 * warranted, matching the "email for formal/important... moderation
 * notifications" guidance.
 */
class ActivityApprovedNotification extends BaseActivityNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'activity.approved',
            "Your activity \"{$this->activity->title}\" has been approved and is now live."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Activity Approved',
            'body' => "\"{$this->activity->title}\" has been approved and is now live for customers to book.",
            'data' => ['activity_id' => (string) $this->activity->id, 'type' => 'activity.approved'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Activity Has Been Approved')
            ->greeting("Hi {$notifiable->name},")
            ->line("Great news — \"{$this->activity->title}\" has been approved and is now live for customers to book.")
            ->line('Thank you for listing with us!');
    }
}
