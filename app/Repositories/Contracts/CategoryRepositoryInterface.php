<?php

namespace App\Repositories\Contracts;

use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface CategoryRepositoryInterface extends BaseRepositoryInterface
{
    public function findBySlug(string $slug): ?Category;

    public function findByIdOrUuidOrSlug(string $value): ?Category;

    public function slugExists(string $slug, ?int $ignoreId = null): bool;

    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator;

    public function activeOrdered(): Collection;
}
