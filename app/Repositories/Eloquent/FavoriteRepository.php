<?php

namespace App\Repositories\Eloquent;

use App\Models\Favorite;
use App\Repositories\Contracts\FavoriteRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FavoriteRepository extends BaseRepository implements FavoriteRepositoryInterface
{
    public function __construct(Favorite $model)
    {
        parent::__construct($model);
    }

    public function exists(int $customerId, int $activityId): bool
    {
        return $this->model->newQuery()
            ->where('customer_id', $customerId)
            ->where('activity_id', $activityId)
            ->exists();
    }

    public function forCustomer(int $customerId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('customer_id', $customerId)
            ->with('activity.category')
            ->latest()
            ->paginate($perPage, ['*'], 'current_page')
            ->withQueryString();
    }
}
