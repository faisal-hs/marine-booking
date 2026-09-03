<?php

namespace App\Listeners\Notifications;

use App\Events\ProviderVerified;
use App\Models\User;
use App\Notifications\Providers\Admin\ProviderVerifiedAdminNotification;
use App\Notifications\Providers\ProviderVerifiedNotification;
use App\Services\NotificationDispatchService;

class SendProviderVerifiedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ProviderVerified $event): void
    {
        $provider = $event->provider;

        $this->dispatcher->dispatchOnce(
            "provider.verified:{$provider->id}",
            $provider->user,
            new ProviderVerifiedNotification($provider)
        );

        foreach ($this->resolveAdminAudience() as $admin) {
            $this->dispatcher->dispatchOnce(
                "admin.provider.verified:{$provider->id}",
                $admin,
                new ProviderVerifiedAdminNotification($provider)
            );
        }
    }

    protected function resolveAdminAudience()
    {
        try {
            return User::permission('providers.view')->get();
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return collect();
        }
    }
}
