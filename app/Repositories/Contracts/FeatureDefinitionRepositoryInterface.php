<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface FeatureDefinitionRepositoryInterface extends BaseRepositoryInterface
{
    public function forCategory(int $categoryId): Collection;

    public function keyExists(string $key, int $categoryId, ?int $ignoreId = null): bool;
}
