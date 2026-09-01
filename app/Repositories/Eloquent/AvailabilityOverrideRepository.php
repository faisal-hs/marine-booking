<?php

namespace App\Repositories\Eloquent;

use App\Models\ActivityAvailabilityOverride;
use App\Repositories\Contracts\AvailabilityOverrideRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class AvailabilityOverrideRepository extends BaseRepository implements AvailabilityOverrideRepositoryInterface
{
    public function __construct(ActivityAvailabilityOverride $model)
    {
        parent::__construct($model);
    }

    public function activeForActivity(int $activityId): Collection
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->where('status', 'active')
            ->orderBy('date_from')
            ->get();
    }

    public function activeOverlappingRange(int $activityId, Carbon $from, Carbon $to): Collection
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->where('status', 'active')
            ->where('date_from', '<=', $to->toDateString())
            ->where('date_to', '>=', $from->toDateString())
            ->get();
    }
}
