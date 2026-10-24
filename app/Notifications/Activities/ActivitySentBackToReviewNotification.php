<?php

namespace App\Notifications\Activities;

use Illuminate\Notifications\Messages\MailMessage;

class ActivitySentBackToReviewNotification extends BaseActivityNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'activity.sent_back_to_review',
            "Your changes to \"{$this->activity->title}\" need to be approved again. It is hidden from customers until then."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Activity Back In Review',
            'body' => "\"{$this->activity->title}\" is hidden until your changes are approved.",
            'data' => ['activity_id' => (string) $this->activity->id, 'type' => 'activity.sent_back_to_review'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Activity Is Back In Review')
            ->greeting("Hi {$notifiable->name},")
            ->line("You changed the details of \"{$this->activity->title}\" while it was live.")
            ->line('Changes to the title, description, photos, category, location, requirements, inclusions or features need to be approved again, so the activity is hidden from customers until an admin reviews it.')
            ->line('Existing bookings are not affected. Changes to your schedule, dates, prices and extras never need a review.');
    }
}
