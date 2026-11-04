<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifications are the in-app feed entries Laravel stores in the "notifications" table.
 * Viewing needs notifications.view. DELETING someone else's entry removes it from their
 * feed in the mobile app, so it needs its own, stronger permission (notifications.delete).
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('notifications.view');
    }

    public function view(User $user, DatabaseNotification $notification): bool
    {
        return $user->can('notifications.view');
    }

    public function delete(User $user, DatabaseNotification $notification): bool
    {
        return $user->can('notifications.delete');
    }
}
