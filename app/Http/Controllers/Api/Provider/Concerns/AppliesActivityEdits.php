<?php

namespace App\Http\Controllers\Api\Provider\Concerns;

use App\Models\Activity;

/**
 * A provider edits their own activity, live or not, and the edit applies at once: nobody reviews an activity, so there is nothing to send it back to.
 * (An admin who must stop an activity suspends it; that is a separate act, see ActivityPublishingService.)
 */
trait AppliesActivityEdits
{
    /** @return array{0: mixed, 1: string} what the edit returned, and the message for the response */
    protected function applyEdit(Activity $activity, callable $edit, string $message): array
    {
        return [$edit(), $message];
    }
}
