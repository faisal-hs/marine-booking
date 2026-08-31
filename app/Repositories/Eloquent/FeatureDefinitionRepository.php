<?php

namespace App\Repositories\Eloquent;

use App\Models\FeatureDefinition;
use App\Repositories\Contracts\FeatureDefinitionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class FeatureDefinitionRepository extends BaseRepository implements FeatureDefinitionRepositoryInterface
{
    public function __construct(FeatureDefinition $model)
    {
        parent::__construct($model);
    }

    public function forCategory(int $categoryId): Collection
    {
        return $this->model->newQuery()
            ->where('category_id', $categoryId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();
    }

    public function keyExists(string $key, int $categoryId, ?int $ignoreId = null): bool
    {
        return $this->model->newQuery()
            ->where('category_id', $categoryId)
            ->where('key', $key)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
