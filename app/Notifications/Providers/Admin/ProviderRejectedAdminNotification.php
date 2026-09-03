<?php

namespace App\Notifications\Providers\Admin;

class ProviderRejectedAdminNotification extends BaseProviderAdminNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'admin.provider.rejected',
            "Provider \"{$this->provider->business_name}\" verification was rejected."
        );
    }
}
