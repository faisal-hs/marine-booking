<?php

namespace App\Events;

use App\Models\ProviderRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProviderRequestSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProviderRequest $request) {}
}
