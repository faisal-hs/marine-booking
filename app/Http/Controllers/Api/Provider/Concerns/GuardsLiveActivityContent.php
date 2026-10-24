<?php

namespace App\Http\Controllers\Api\Provider\Concerns;

use App\Models\Activity;
use App\Services\LiveActivityContentGuard;

/**
 * For provider endpoints that change what customers see on an activity: wraps the
 * edit in LiveActivityContentGuard and tells the provider, in the response message,
 * when their change sent a live activity back to review.
 */
trait GuardsLiveActivityContent
{
    /**
     * @return array{0: mixed, 1: string} [the edit's result, the message to return]
     */
    protected function editWithReviewGuard(Activity $activity, callable $edit, string $message): array
    {
        $wasLive = $activity->status === 'published';

        $result = app(LiveActivityContentGuard::class)->run($activity, $edit);

        if ($wasLive && $activity->status !== 'published') {
            $message .= ' This activity was live, so it has been sent back for review and is hidden until an admin approves it.';
        }

        return [$result, $message];
    }
}
