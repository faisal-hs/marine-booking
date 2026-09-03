<?php

namespace App\Listeners\Notifications;

use App\Events\ProviderRejected;
use App\Models\User;
use App\Notifications\Providers\Admin\ProviderRejectedAdminNotification;
use App\Notifications\Providers\ProviderRejectedNotification;
use App\Services\NotificationDispatchService;

class SendProviderRejectedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ProviderRejected $event): void
    {
        $provider = $event->provider;

        $this->dispatcher->dispatchOnce(
            "provider.rejected:{$provider->id}",
            $provider->user,
            new ProviderRejectedNotification($provider)
        );

        foreach ($this->resolveAdminAudience() as $admin) {
            $this->dispatcher->dispatchOnce(
                "admin.provider.rejected:{$provider->id}",
                $admin,
                new ProviderRejectedAdminNotification($provider)
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
