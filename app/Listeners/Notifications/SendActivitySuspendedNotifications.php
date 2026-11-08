<?php

namespace App\Listeners\Notifications;

use App\Events\ActivitySuspended;
use App\Notifications\Activities\ActivitySuspendedNotification;
use App\Services\NotificationDispatchService;

class SendActivitySuspendedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ActivitySuspended $event): void
    {
        $activity = $event->activity;

        $this->dispatcher->dispatchOnce(
            "activity.suspended:{$activity->id}:".($activity->suspended_at?->timestamp ?? 'lifted'),
            $activity->provider->user,
            new ActivitySuspendedNotification($activity)
        );
    }
}
