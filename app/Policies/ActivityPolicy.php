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
        return $user->isProvider() && $user->provider?->id === $activity->provider_id;
    }

    public function update(User $user, Activity $activity): bool
    {
        return $user->isProvider() && $user->provider?->id === $activity->provider_id;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return $user->isProvider() && $user->provider?->id === $activity->provider_id;
    }

    /**
     * Admin moderation actions (Module 06) — permission-based, not
     * ownership-based. Deliberately separate from the provider-facing
     * methods above rather than reusing 'update', since an admin acting
     * on someone else's activity is a fundamentally different
     * authorization question than a provider acting on their own.
     */
    public function approve(User $user): bool
    {
        return $user->can('activities.approve');
    }

    public function reject(User $user): bool
    {
        return $user->can('activities.reject');
    }

    public function publish(User $user): bool
    {
        return $user->can('activities.publish');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('activities.view');
    }

    public function viewAsAdmin(User $user): bool
    {
        return $user->can('activities.view');
    }
}
