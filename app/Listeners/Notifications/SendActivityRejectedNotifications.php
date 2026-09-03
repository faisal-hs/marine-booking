<?php

namespace App\Listeners\Notifications;

use App\Events\ActivityRejected;
use App\Notifications\Activities\ActivityRejectedNotification;
use App\Services\NotificationDispatchService;

class SendActivityRejectedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ActivityRejected $event): void
    {
        $activity = $event->activity;

        $this->dispatcher->dispatchOnce(
            "activity.rejected:{$activity->id}",
            $activity->provider->user,
            new ActivityRejectedNotification($activity)
        );
    }
}
