<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.create');
    }

    /** Editing the profile, suspending and reactivating. */
    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.update');
    }

    /** Deleting and restoring. */
    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete');
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete');
    }
}
