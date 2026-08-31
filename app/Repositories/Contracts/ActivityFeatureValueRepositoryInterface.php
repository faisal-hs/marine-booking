<?php

namespace App\Repositories\Contracts;

use App\Models\ActivityFeatureValue;
use Illuminate\Database\Eloquent\Collection;

interface ActivityFeatureValueRepositoryInterface extends BaseRepositoryInterface
{
    public function forActivity(int $activityId): Collection;

    public function deleteForActivity(int $activityId): void;

    public function upsert(int $activityId, int $definitionId, array $columns): ActivityFeatureValue;
}
