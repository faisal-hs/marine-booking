<?php

namespace App\Repositories\Eloquent;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->model->newQuery()->where('slug', $slug)->first();
    }

    public function findByIdOrUuidOrSlug(string $value): ?Category
    {
        if (is_numeric($value)) {
            return $this->model->newQuery()->find((int) $value);
        }

        if (Str::isUuid($value)) {
            return $this->findByUuid($value);
        }

        return $this->findBySlug($value);
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return $this->model->newQuery()
            ->where('slug', $slug)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->orderBy('sort_order')->paginate($perPage)->withQueryString();
    }

    public function activeOrdered(): Collection
    {
        return $this->model->newQuery()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();
    }
}
