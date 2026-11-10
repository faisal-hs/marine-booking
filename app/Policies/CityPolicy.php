<?php

namespace App\Policies;

use App\Models\User;

/** Who may manage the cities (destinations) activities can be in. */
class CityPolicy
{
    public function manage(User $user): bool
    {
        return $user->can('cities.manage');
    }
}
