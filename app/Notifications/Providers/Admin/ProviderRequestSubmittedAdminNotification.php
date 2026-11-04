<?php

namespace App\Notifications\Providers\Admin;

use App\Models\ProviderRequest;
use Illuminate\Notifications\Notification;

/** Tells the admins who may review provider requests that one is waiting. */
class ProviderRequestSubmittedAdminNotification extends Notification
{
    public function __construct(protected ProviderRequest $providerRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $provider = $this->providerRequest->provider;
        $applicant = $this->providerRequest->user;

        return [
            'type' => 'admin.provider_request.submitted',
            'provider_request_uuid' => $this->providerRequest->uuid,
            'provider_uuid' => $provider->uuid,
            'business_name' => $provider->business_name,
            'source' => $this->providerRequest->source,
            'message' => "{$applicant->name} asked to become a provider (\"{$provider->business_name}\", {$provider->city}).",
        ];
    }
}
