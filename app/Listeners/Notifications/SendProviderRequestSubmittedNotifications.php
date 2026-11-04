<?php

namespace App\Listeners\Notifications;

use App\Events\ProviderRequestSubmitted;
use App\Models\User;
use App\Notifications\Providers\Admin\ProviderRequestSubmittedAdminNotification;
use App\Services\NotificationDispatchService;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SendProviderRequestSubmittedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(ProviderRequestSubmitted $event): void
    {
        foreach ($this->resolveAdminAudience() as $admin) {
            // One per admin and per request, so a re-delivered event does not repeat it.
            $this->dispatcher->dispatchOnce(
                "admin.provider_request.submitted:{$event->request->id}",
                $admin,
                new ProviderRequestSubmittedAdminNotification($event->request)
            );
        }
    }

    /** Only the admins who may act on a request are told about it. */
    protected function resolveAdminAudience()
    {
        try {
            return User::permission('provider_requests.review')->get();
        } catch (PermissionDoesNotExist) {
            return collect();
        }
    }
}
