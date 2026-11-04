<?php

namespace App\Notifications\Broadcasts;

use App\Notifications\Concerns\QueuedDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * An admin announcement as one recipient receives it: an in-app entry and a push.
 *
 * It carries plain strings, not the Broadcast model, on purpose: an admin may delete the
 * broadcast record while thousands of these are still waiting in the queue, and a queued job
 * that referenced a deleted model would fail.
 */
class BroadcastNotification extends Notification implements ShouldQueue
{
    use QueuedDelivery;

    public function __construct(
        protected string $title,
        protected string $body,
        protected string $broadcastUuid,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'broadcast',
            'title' => $this->title,
            'message' => $this->body,
            'broadcast_uuid' => $this->broadcastUuid,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->title,
            // limit() adds "..." after the cut, so 177 keeps the whole body within 180 characters.
            'body' => Str::limit($this->body, 177),
            'data' => ['type' => 'broadcast', 'broadcast_uuid' => $this->broadcastUuid],
        ];
    }
}
