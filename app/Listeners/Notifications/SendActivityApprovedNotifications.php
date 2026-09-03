<?php

namespace App\Listeners\Notifications;

use App\Events\ActivityApproved;
use App\Notifications\Activities\ActivityApprovedNotification;
use App\Services\NotificationDispatchService;

class SendActivityApprovedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ActivityApproved $event): void
    {
        $activity = $event->activity;

        $this->dispatcher->dispatchOnce(
            "activity.approved:{$activity->id}",
            $activity->provider->user,
            new ActivityApprovedNotification($activity)
        );
    }
}
