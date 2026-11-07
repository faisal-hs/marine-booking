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
            $this->live()
                ? "Your activity \"{$this->activity->title}\" has been approved and is now live."
                : "Your activity \"{$this->activity->title}\" has been approved. Publish it when you are ready to take bookings."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Activity Approved',
            'body' => $this->live()
                ? "\"{$this->activity->title}\" has been approved and is now live for customers to book."
                : "\"{$this->activity->title}\" has been approved. Publish it when you are ready for customers to book.",
            'data' => ['activity_id' => (string) $this->activity->id, 'type' => 'activity.approved'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Activity Has Been Approved')
            ->greeting("Hi {$notifiable->name},")
            ->line($this->live()
                ? "Great news — \"{$this->activity->title}\" has been approved and is now live for customers to book."
                : "Great news — \"{$this->activity->title}\" has been approved. It is not visible to customers until you publish it.")
            ->line('Thank you for listing with us!');
    }

    /** Approved AND published (automatic publishing is on), as opposed to approved and waiting for the provider. */
    private function live(): bool
    {
        return $this->activity->status === 'published';
    }
}
