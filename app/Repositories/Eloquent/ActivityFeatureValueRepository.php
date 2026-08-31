<?php

namespace App\Repositories\Eloquent;

use App\Models\ActivityFeatureValue;
use App\Repositories\Contracts\ActivityFeatureValueRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ActivityFeatureValueRepository extends BaseRepository implements ActivityFeatureValueRepositoryInterface
{
    public function __construct(ActivityFeatureValue $model)
    {
        parent::__construct($model);
    }

    public function forActivity(int $activityId): Collection
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->with('featureDefinition')
            ->get();
    }

    public function deleteForActivity(int $activityId): void
    {
        $this->model->newQuery()->where('activity_id', $activityId)->delete();
    }

    public function upsert(int $activityId, int $definitionId, array $columns): ActivityFeatureValue
    {
        return $this->model->newQuery()->updateOrCreate(
            ['activity_id' => $activityId, 'feature_definition_id' => $definitionId],
            $columns
        );
    }
}
