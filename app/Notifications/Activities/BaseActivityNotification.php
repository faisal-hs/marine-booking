<?php

namespace App\Notifications\Activities;

use App\Models\Activity;
use App\Notifications\Concerns\QueuedDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Provider-facing only — no admin equivalent exists for these three
 * events, deliberately: admins are the ACTOR performing the moderation
 * action themselves, not a passive party needing to be told about their
 * own action, unlike reservation/payment events where admins are
 * genuinely monitoring something a customer did.
 */
abstract class BaseActivityNotification extends Notification implements ShouldQueue
{
    use QueuedDelivery;

    public function __construct(protected Activity $activity) {}

    protected function baseData(string $type, string $message): array
    {
        return [
            'type' => $type,
            'activity_id' => $this->activity->id,
            'activity_uuid' => $this->activity->uuid,
            'activity_title' => $this->activity->title,
            'message' => $message,
        ];
    }
}
