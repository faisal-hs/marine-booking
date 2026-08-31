<?php

namespace App\Repositories\Eloquent;

use App\Models\Provider;
use App\Repositories\Contracts\ProviderRepositoryInterface;

class ProviderRepository extends BaseRepository implements ProviderRepositoryInterface
{
    public function __construct(Provider $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Provider
    {
        return $this->model->newQuery()->where('slug', $slug)->first();
    }

    public function findByUserId(int $userId): ?Provider
    {
        return $this->model->newQuery()->where('user_id', $userId)->first();
    }

    public function slugExists(string $slug): bool
    {
        return $this->model->newQuery()->where('slug', $slug)->exists();
    }
}
