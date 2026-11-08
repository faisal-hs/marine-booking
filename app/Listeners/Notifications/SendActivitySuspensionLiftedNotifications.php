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
            "activity.lifted:{$activity->id}:".now()->timestamp,
            $activity->provider->user,
            new ActivitySuspensionLiftedNotification($activity)
        );
    }
}
