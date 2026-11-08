<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;

class ActivityPolicy
{
    /**
     * A provider may only act on their own activities. Admins bypass
     * ownership via the 'activities.*' permission set — this policy
     * governs the PROVIDER self-service API only.
     */
    public function view(User $user, Activity $activity): bool
    {
        return $user->provider !== null && $user->provider->id === $activity->provider_id;
    }

    public function update(User $user, Activity $activity): bool
    {
        return $user->provider !== null && $user->provider->id === $activity->provider_id;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return $user->provider !== null && $user->provider->id === $activity->provider_id;
    }

    /**
     * Admin moderation actions (Module 06) — permission-based, not
     * ownership-based. Deliberately separate from the provider-facing
     * methods above rather than reusing 'update', since an admin acting
     * on someone else's activity is a fundamentally different
     * authorization question than a provider acting on their own.
     */
    /** Suspending an activity, and lifting the suspension. */
    public function suspend(User $user): bool
    {
        return $user->can('activities.suspend');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('activities.view');
    }

    /**
     * Admin-side create, edit, delete and restore. Named separately because update()/delete()
     * above are the provider's rule for THEIR OWN activities. Approving or publishing is a
     * different permission again, so a content admin can write listings without releasing them.
     */
    public function adminCreate(User $user): bool
    {
        return $user->can('activities.create');
    }

    public function adminUpdate(User $user, Activity $activity): bool
    {
        return $user->can('activities.update');
    }

    public function adminDelete(User $user, Activity $activity): bool
    {
        return $user->can('activities.delete');
    }

    public function adminRestore(User $user, Activity $activity): bool
    {
        return $user->can('activities.delete');
    }

    public function viewAsAdmin(User $user): bool
    {
        return $user->can('activities.view');
    }
}
