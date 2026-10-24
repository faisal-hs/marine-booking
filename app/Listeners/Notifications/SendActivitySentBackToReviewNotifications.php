<?php

namespace App\Listeners\Notifications;

use App\Events\ActivitySentBackToReview;
use App\Notifications\Activities\ActivitySentBackToReviewNotification;
use App\Services\NotificationDispatchService;

/**
 * Provider only. Admins already see the activity in the pending-review count on
 * their dashboard, the same as for a brand new submission.
 */
class SendActivitySentBackToReviewNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ActivitySentBackToReview $event): void
    {
        $activity = $event->activity;

        $this->dispatcher->dispatchOnce(
            // One activity can be sent back repeatedly, so the key is per occurrence.
            "activity.sent_back:{$activity->id}:{$event->occurrenceId}",
            $activity->provider->user,
            new ActivitySentBackToReviewNotification($activity)
        );
    }
}
