<?php

namespace App\Notifications\Activities;

/**
 * Channel matrix decision: database + fcm only. This fires specifically
 * from the re-publish-after-deactivation path (ActivityModerationService::
 * publish()), a routine, lower-stakes action than the original approval —
 * not significant enough to warrant a second formal email.
 */
class ActivityPublishedNotification extends BaseActivityNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'activity.published',
            "Your activity \"{$this->activity->title}\" is live again."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Activity Published',
            'body' => "\"{$this->activity->title}\" is live again for customers to book.",
            'data' => ['activity_id' => (string) $this->activity->id, 'type' => 'activity.published'],
        ];
    }
}
