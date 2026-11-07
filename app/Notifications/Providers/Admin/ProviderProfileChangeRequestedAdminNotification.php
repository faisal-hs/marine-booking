<?php

namespace App\Notifications\Providers\Admin;

use App\Models\ProviderProfileChange;
use Illuminate\Notifications\Notification;

/** Tells the admins who manage providers that a provider is waiting for a profile change to be reviewed. */
class ProviderProfileChangeRequestedAdminNotification extends Notification
{
    public function __construct(protected ProviderProfileChange $change) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $provider = $this->change->provider;
        $fields = implode(', ', str_replace('_', ' ', $this->change->fields()));

        return [
            'type' => 'admin.provider_profile_change.requested',
            'provider_uuid' => $provider->uuid,
            'provider_profile_change_uuid' => $this->change->uuid,
            'business_name' => $provider->business_name,
            'message' => "\"{$provider->business_name}\" asked to change its {$fields}. The change waits for your review.",
        ];
    }
}
