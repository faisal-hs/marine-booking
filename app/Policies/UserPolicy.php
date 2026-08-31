<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('users.update');
    }

    public function delete(User $user, User $target): bool
    {
        // Prevent an admin from ever deleting their own account through this screen.
        return $user->can('users.delete') && $user->id !== $target->id;
    }
}
