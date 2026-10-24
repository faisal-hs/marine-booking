<?php

namespace App\Notifications\Concerns;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Shared delivery policy for every notification that reaches an external
 * service (FCM push, SMS gateway, SMTP) — the customer- and
 * provider-facing notifications.
 *
 * Why these are queued: Laravel turns a queued notification into ONE JOB
 * PER CHANNEL, so a failing SMS gateway is retried on its own without
 * re-sending the in-app notification or the email, and — just as
 * important — a slow or failing third party can no longer block, or
 * 500, the request that triggered it (a payment webhook, a reservation
 * confirmation).
 *
 * Operational requirement: a queue worker MUST be running
 * (`php artisan queue:work`, supervised in production). Without one,
 * these notifications — including the in-app feed entries — are never
 * created, because the database channel also runs inside its queued job.
 *
 * Admin notifications deliberately do NOT use this trait: they are
 * database-only (no external I/O), so queueing them would add a worker
 * dependency for no benefit.
 *
 * Caller contract: dispatch from outside an open DB transaction (every
 * current call site already does — events fire after the transaction
 * that created the row has committed). A queued job that ran before its
 * transaction committed could fail to restore the serialized model.
 *
 * SerializesModels stores only the model's key in the job payload and
 * re-fetches it when the job runs, so the notification always renders
 * current data rather than a stale snapshot.
 */
trait QueuedDelivery
{
    use Queueable, SerializesModels;

    /** Attempts per channel job before it lands in failed_jobs. */
    public int $tries = 3;

    /** Seconds to wait before the 2nd and 3rd attempt. */
    public array $backoff = [30, 120];
}
