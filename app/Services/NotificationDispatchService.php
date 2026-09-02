<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point every domain Service should call to send a
 * notification — never call $notifiable->notify() directly from
 * business logic. This keeps notification dispatch fully decoupled
 * (a Service only needs to know "this event happened", not which
 * channels/recipients care about it) and guarantees idempotency: the
 * SAME event_key for the SAME recipient can only ever result in one
 * actual send, even if the calling code is accidentally invoked twice
 * (e.g. a retried job, a webhook redelivery).
 *
 * The uniqueness check is enforced by a real DB unique constraint
 * (notification_dispatch_log), not a check-then-insert race — two
 * concurrent calls for the same (recipient, event_key) will have
 * exactly one succeed and one catch UniqueConstraintViolationException.
 */
class NotificationDispatchService
{
    /**
     * @return bool True if this call actually sent the notification,
     *              false if it was a no-op duplicate.
     */
    public function dispatchOnce(string $eventKey, $notifiable, Notification $notification): bool
    {
        try {
            DB::table('notification_dispatch_log')->insert([
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id,
                'event_key' => $eventKey,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        $notifiable->notify($notification);

        return true;
    }
}
