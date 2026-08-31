<?php

namespace App\Policies;

use App\Models\FeatureDefinition;
use App\Models\User;

class FeatureDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('feature_definitions.view');
    }

    public function view(User $user, FeatureDefinition $definition): bool
    {
        return $user->can('feature_definitions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('feature_definitions.create');
    }

    public function update(User $user, FeatureDefinition $definition): bool
    {
        return $user->can('feature_definitions.update');
    }

    public function delete(User $user, FeatureDefinition $definition): bool
    {
        return $user->can('feature_definitions.delete');
    }
}
