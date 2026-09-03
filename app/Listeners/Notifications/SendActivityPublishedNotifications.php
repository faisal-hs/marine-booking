<?php

namespace App\Listeners\Notifications;

use App\Events\ActivityPublished;
use App\Notifications\Activities\ActivityPublishedNotification;
use App\Services\NotificationDispatchService;

class SendActivityPublishedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ActivityPublished $event): void
    {
        $activity = $event->activity;

        $this->dispatcher->dispatchOnce(
            "activity.published:{$activity->id}",
            $activity->provider->user,
            new ActivityPublishedNotification($activity)
        );
    }
}
