<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface ScheduleRepositoryInterface extends BaseRepositoryInterface
{
    public function activeForActivity(int $activityId): Collection;

    public function allForActivity(int $activityId): Collection;
}
