<?php

namespace App\Events;

use App\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ActivitySentBackToReview
{
    use Dispatchable, SerializesModels;

    /**
     * An activity can be sent back to review many times over its life, so the
     * notification idempotency key needs something unique to THIS occurrence,
     * while staying stable if the same event is handled twice.
     */
    public string $occurrenceId;

    public function __construct(public Activity $activity)
    {
        $this->occurrenceId = (string) Str::uuid();
    }
}
