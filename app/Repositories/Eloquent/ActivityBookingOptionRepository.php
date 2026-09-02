<?php

namespace App\Repositories\Eloquent;

use App\Models\ActivityBookingOption;
use App\Repositories\Contracts\ActivityBookingOptionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ActivityBookingOptionRepository extends BaseRepository implements ActivityBookingOptionRepositoryInterface
{
    public function __construct(ActivityBookingOption $model)
    {
        parent::__construct($model);
    }

    public function forActivity(int $activityId): Collection
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->orderBy('sort_order')
            ->get();
    }

    public function activeForActivity(int $activityId): Collection
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function slugExistsForActivity(int $activityId, string $slug, ?int $ignoreId = null): bool
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->where('slug', $slug)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    /**
     * "Only one default booking option per activity" (spec) — enforced
     * here at write time, since MySQL has no conditional/partial unique
     * index to express this at the schema level (see Step 1's migration
     * comment). Called before setting a new option as default.
     */
    public function demoteExistingDefaults(int $activityId, ?int $exceptId = null): void
    {
        $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->where('is_default', true)
            ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }
}
