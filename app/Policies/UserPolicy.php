<?php

namespace App\Policies;

use App\Models\User;

/**
 * Abilities for the STAFF screen (user_type "admin"). Providers and customers are
 * authorised by ProviderPolicy and CustomerPolicy.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $target->isAdmin() && $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $target->isAdmin() && $user->can('users.update') && $this->mayTouchSuperAdmin($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        // You cannot delete yourself, and only a super admin can delete a super admin.
        return $target->isAdmin()
            && $user->can('users.delete')
            && $user->id !== $target->id
            && $this->mayTouchSuperAdmin($user, $target);
    }

    /** An admin must not be able to edit, suspend or delete the accounts above them. */
    private function mayTouchSuperAdmin(User $user, User $target): bool
    {
        return ! $target->hasRole('super_admin') || $user->hasRole('super_admin');
    }
}
