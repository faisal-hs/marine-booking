<?php

namespace App\Notifications\Activities;

use Illuminate\Notifications\Messages\MailMessage;

/** An admin lifted the suspension: the activity is back as it was. */
class ActivitySuspensionLiftedNotification extends BaseActivityNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    private function sentence(): string
    {
        return $this->activity->status === 'published'
            ? "The suspension of \"{$this->activity->title}\" was lifted. It is live again."
            : "The suspension of \"{$this->activity->title}\" was lifted. It is still off the site until you activate it.";
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData('activity.suspension_lifted', $this->sentence());
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Suspension Lifted',
            'body' => $this->sentence(),
            'data' => ['activity_id' => (string) $this->activity->id, 'type' => 'activity.suspension_lifted'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your Activity Suspension Was Lifted')->greeting("Hi {$notifiable->name},")->line($this->sentence());
    }
}
