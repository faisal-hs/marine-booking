<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;

class ActivityPolicy
{
    /**
     * A provider may only act on their own activities. Admins bypass
     * ownership via the 'activities.*' permission set (checked separately
     * in the admin surface built in Module 06) — this policy governs the
     * PROVIDER self-service API only.
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
}
