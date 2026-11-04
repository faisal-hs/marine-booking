<?php

namespace App\Policies;

use App\Models\ProviderRequest;
use App\Models\User;

/**
 * Provider requests are seen and decided only by the admins who hold these two permissions, which the
 * seeder gives to the admin and super admin roles and to no one else.
 */
class ProviderRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('provider_requests.view');
    }

    public function view(User $user, ProviderRequest $request): bool
    {
        return $user->can('provider_requests.view');
    }

    /** Approving and rejecting. */
    public function review(User $user, ProviderRequest $request): bool
    {
        return $user->can('provider_requests.review');
    }
}
