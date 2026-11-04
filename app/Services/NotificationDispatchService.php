<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point every domain Service should call to send a
 * notification — never call $notifiable->notify() directly from
 * business logic. This keeps notification dispatch decoupled (a Service
 * only needs to know "this event happened", not which channels or
 * recipients care about it), idempotent, and failure-isolated.
 *
 * IDEMPOTENCY: the same event_key for the same recipient results in at
 * most one dispatch. The guarantee is a real DB unique constraint on
 * notification_dispatch_log, not a check-then-insert race — two
 * concurrent calls for the same (recipient, event_key) have exactly one
 * succeed and one hit UniqueConstraintViolationException.
 *
 * FAILURE ISOLATION: a notification problem must never break the
 * business operation that triggered it (a payment webhook, a
 * reservation confirmation), and must never be silently lost. So:
 *
 *  - The dedup row is claimed first (that is what makes the call
 *    race-safe), but it is RELEASED again if handing the notification
 *    off throws. Otherwise a failed send would leave a row saying
 *    "already sent", every retry would be rejected as a duplicate, and
 *    the notification would be lost forever.
 *  - The exception is reported (logged / sent to the error tracker) and
 *    swallowed here instead of propagating into the caller.
 *
 * With the customer/provider notifications queued (see QueuedDelivery),
 * notify() only enqueues one job per channel, so an exception here
 * means NOTHING was dispatched and releasing the row is exactly right;
 * per-channel delivery failures are then retried by the queue worker
 * (and end up in failed_jobs), not by this method.
 *
 * A RECIPIENT THAT NO LONGER EXISTS is skipped, not an error. Listeners
 * reach the recipient through relations such as $activity->provider->user.
 * A soft-deleted user (admin screens soft-delete, per the "never hard-delete
 * user records" rule) is either null or, through the Provider/Customer
 * relations, returned as a trashed model; both are skipped. Before this
 * guard, that null reached get_class() below and threw a TypeError out of
 * the listener, which broke the very operation that fired the event: a
 * customer paying for an activity whose provider account had been deleted
 * got a server error AFTER their reservation was already confirmed.
 *
 * Known caveat, sync queue driver only (tests / local dev): there each
 * channel runs inline, so if channel N throws, channels before it have
 * already delivered, and a later retry of the same event_key would
 * deliver those again. At-least-once delivery was chosen deliberately
 * over silent loss; production uses a real queue, where this cannot
 * happen.
 */
class NotificationDispatchService
{
    /**
     * @return bool True if the notification was handed off, false if it
     *              was a duplicate, had no recipient, or the hand-off failed
     *              (failures are reported, never thrown).
     */
    public function dispatchOnce(string $eventKey, ?object $notifiable, Notification $notification): bool
    {
        // A missing recipient, or an account that was soft-deleted (the Provider and
        // Customer relations still return it so screens can show who it was).
        if ($notifiable === null || (method_exists($notifiable, 'trashed') && $notifiable->trashed())) {
            Log::warning("Notification [{$eventKey}] skipped: its recipient no longer exists (a deleted account).");

            return false;
        }

        try {
            $logId = DB::table('notification_dispatch_log')->insertGetId([
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id,
                'event_key' => $eventKey,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        try {
            $notifiable->notify($notification);
        } catch (\Throwable $e) {
            DB::table('notification_dispatch_log')->where('id', $logId)->delete();

            report($e);

            return false;
        }

        return true;
    }
}
