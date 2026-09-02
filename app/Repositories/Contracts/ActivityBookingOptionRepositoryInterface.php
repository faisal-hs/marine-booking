<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface ActivityBookingOptionRepositoryInterface extends BaseRepositoryInterface
{
    public function forActivity(int $activityId): Collection;

    public function activeForActivity(int $activityId): Collection;

    public function slugExistsForActivity(int $activityId, string $slug, ?int $ignoreId = null): bool;

    public function demoteExistingDefaults(int $activityId, ?int $exceptId = null): void;
}
