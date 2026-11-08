<?php

namespace App\Listeners\Notifications;

use App\Events\ActivitySuspensionLifted;
use App\Notifications\Activities\ActivitySuspensionLiftedNotification;
use App\Services\NotificationDispatchService;

class SendActivitySuspensionLiftedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ActivitySuspensionLifted $event): void
    {
        $activity = $event->activity;

        $this->dispatcher->dispatchOnce(
            "activity.lifted:{$activity->id}:{$event->occurrence}",
            $activity->provider->user,
            new ActivitySuspensionLiftedNotification($activity)
        );
    }
}
