<?php

namespace App\Repositories\Eloquent;

use App\Models\Review;
use App\Repositories\Contracts\ReviewRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReviewRepository extends BaseRepository implements ReviewRepositoryInterface
{
    public function __construct(Review $model)
    {
        parent::__construct($model);
    }

    public function existsForCustomerAndActivity(int $customerId, int $activityId): bool
    {
        return $this->model->newQuery()
            ->where('customer_id', $customerId)
            ->where('activity_id', $activityId)
            ->exists();
    }

    public function forActivity(int $activityId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->where('is_hidden', false)
            ->with('customer.user')
            ->latest()
            ->paginate($perPage, ['*'], 'current_page')
            ->withQueryString();
    }

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with(['customer.user', 'activity']);

        if (isset($filters['is_hidden'])) {
            $query->where('is_hidden', $filters['is_hidden']);
        }

        if (! empty($filters['activity'])) {
            $value = $filters['activity'];
            is_numeric($value)
                ? $query->where('activity_id', $value)
                : $query->whereHas('activity', fn($q) => $q->where('uuid', $value));
        }

        $direction = ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';

        return $query->orderBy('created_at', $direction)->orderBy('id', $direction)
            ->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }
}
