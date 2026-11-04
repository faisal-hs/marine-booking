<?php

namespace App\Policies;

use App\Models\Provider;
use App\Models\User;

class ProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('providers.view');
    }

    public function view(User $user, Provider $provider): bool
    {
        return $user->can('providers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('providers.create');
    }

    /** Editing the profile, suspending and reactivating. */
    public function update(User $user, Provider $provider): bool
    {
        return $user->can('providers.update');
    }

    /** Deleting and restoring. */
    public function delete(User $user, Provider $provider): bool
    {
        return $user->can('providers.delete');
    }

    public function restore(User $user, Provider $provider): bool
    {
        return $user->can('providers.delete');
    }

    public function verify(User $user): bool
    {
        return $user->can('providers.verify');
    }

    public function reject(User $user): bool
    {
        return $user->can('providers.reject');
    }
}
