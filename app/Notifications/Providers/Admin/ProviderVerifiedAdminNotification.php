<?php

namespace App\Notifications\Providers\Admin;

class ProviderVerifiedAdminNotification extends BaseProviderAdminNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'admin.provider.verified',
            "Provider \"{$this->provider->business_name}\" was verified."
        );
    }
}
