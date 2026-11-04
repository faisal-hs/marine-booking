<?php

namespace App\Jobs;

use App\Services\BroadcastService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hands an announcement to every recipient. One attempt only: a retry would not message anyone
 * twice (the dispatch service is idempotent per recipient), but a job that fails is better
 * marked failed and shown to the admin than silently retried; they can see what happened.
 */
class SendBroadcast implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $broadcastId) {}

    public function handle(BroadcastService $service): void
    {
        $service->deliver($this->broadcastId);
    }

    public function failed(Throwable $e): void
    {
        app(BroadcastService::class)->markFailed($this->broadcastId, $e->getMessage());
    }
}
