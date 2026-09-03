<?php

namespace App\Policies;

use App\Models\User;

/**
 * Admin verification actions only — permission-based, matching
 * ActivityPolicy's approve()/reject() pattern exactly (dedicated
 * permissions per action, not a shared 'update' permission, since an
 * admin verifying someone else's provider account is a fundamentally
 * different authorization question than a provider editing their own
 * profile — which isn't governed by this Policy at all).
 */
class ProviderPolicy
{
    public function verify(User $user): bool
    {
        return $user->can('providers.verify');
    }

    public function reject(User $user): bool
    {
        return $user->can('providers.reject');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('providers.view');
    }
}
