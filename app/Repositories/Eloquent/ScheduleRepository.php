<?php

namespace App\Repositories\Eloquent;

use App\Models\ActivitySchedulePeriod;
use App\Repositories\Contracts\ScheduleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ScheduleRepository extends BaseRepository implements ScheduleRepositoryInterface
{
    public function __construct(ActivitySchedulePeriod $model)
    {
        parent::__construct($model);
    }

    public function activeForActivity(int $activityId): Collection
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->where('status', 'active')
            ->orderBy('day_of_week')
            ->orderBy('sort_order')
            ->get();
    }

    public function allForActivity(int $activityId): Collection
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->orderBy('day_of_week')
            ->orderBy('sort_order')
            ->get();
    }
}
