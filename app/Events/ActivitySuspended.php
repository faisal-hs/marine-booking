<?php

namespace App\Events;

use App\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActivitySuspended
{
    use Dispatchable, SerializesModels;

    /** Identifies THIS occurrence, so a listener registered twice tells the provider once, and two suspensions in the same second are still two. */
    public string $occurrence;

    public function __construct(public Activity $activity)
    {
        $this->occurrence = (string) \Illuminate\Support\Str::uuid();
    }
}
