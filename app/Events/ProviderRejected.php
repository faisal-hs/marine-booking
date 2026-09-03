<?php

namespace App\Events;

use App\Models\Provider;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProviderRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(public Provider $provider) {}
}
