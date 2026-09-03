<?php

namespace App\Notifications\Providers;

use App\Models\Provider;
use Illuminate\Notifications\Notification;

abstract class BaseProviderNotification extends Notification
{
    public function __construct(protected Provider $provider) {}

    protected function baseData(string $type, string $message): array
    {
        return [
            'type' => $type,
            'provider_id' => $this->provider->id,
            'provider_uuid' => $this->provider->uuid,
            'business_name' => $this->provider->business_name,
            'message' => $message,
        ];
    }
}
