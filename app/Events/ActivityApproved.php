<?php

namespace App\Events;

use App\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActivityApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public Activity $activity) {}
}
