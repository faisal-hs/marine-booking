<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface AvailabilityOverrideRepositoryInterface extends BaseRepositoryInterface
{
    public function activeForActivity(int $activityId): Collection;

    /**
     * All active overrides that overlap the given [from, to] date range at
     * all — a single query used to resolve an entire range/month in memory,
     * rather than one query per date.
     */
    public function activeOverlappingRange(int $activityId, Carbon $from, Carbon $to): Collection;
}
