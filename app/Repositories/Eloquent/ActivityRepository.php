<?php

namespace App\Repositories\Eloquent;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class ActivityRepository extends BaseRepository implements ActivityRepositoryInterface
{
    public function __construct(Activity $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Activity
    {
        return $this->model->newQuery()->where('slug', $slug)->first();
    }

    public function findByIdOrUuidOrSlug(string $value): ?Activity
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

    public function paginateForProvider(int $providerId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('provider_id', $providerId)
            ->with(['category', 'images', 'featureValues.featureDefinition', 'options', 'requirements', 'inclusions', 'schedulePeriods', 'availabilityOverrides']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }

    public function paginatePublished(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('status', 'published')
            ->with(['category', 'provider', 'images']);

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['search'])) {
            $query->where('title', 'like', '%' . $filters['search'] . '%');
        }

        return $query->latest()->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['category', 'provider']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $query->where('title', 'like', '%' . $filters['search'] . '%');
        }

        return $query->latest()->paginate($perPage)->withQueryString();
    }
}
