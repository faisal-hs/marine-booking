<?php

namespace App\Policies;

use App\Models\Broadcast;
use App\Models\User;

class BroadcastPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('notifications.view');
    }

    public function view(User $user, Broadcast $broadcast): bool
    {
        return $user->can('notifications.view');
    }

    /** Composing, editing a draft and sending: reaching every customer or provider is the powerful part. */
    public function create(User $user): bool
    {
        return $user->can('notifications.create');
    }

    public function update(User $user, Broadcast $broadcast): bool
    {
        return $user->can('notifications.create');
    }

    public function send(User $user, Broadcast $broadcast): bool
    {
        return $user->can('notifications.create');
    }

    public function delete(User $user, Broadcast $broadcast): bool
    {
        return $user->can('notifications.delete');
    }
}
