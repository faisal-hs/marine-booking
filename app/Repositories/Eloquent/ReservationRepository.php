<?php

namespace App\Repositories\Eloquent;

use App\Models\Reservation;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReservationRepository extends BaseRepository implements ReservationRepositoryInterface
{
    public function __construct(Reservation $model)
    {
        parent::__construct($model);
    }

    public function consumedCapacity(int $bookingOptionId, ?int $schedulePeriodId, string $date): array
    {
        $query = $this->model->newQuery()
            ->where('activity_booking_option_id', $bookingOptionId)
            ->where('reservation_date', $date);

        if ($schedulePeriodId !== null) {
            $query->where('schedule_period_id', $schedulePeriodId);
        } else {
            $query->whereNull('schedule_period_id');
        }

        // 'status' is compared here as the raw string stored in the DB —
        // Eloquent's enum cast only affects hydrated model attributes, not
        // how the query builder's where() filters against the column.
        $query->where(function ($q) {
            $q->where('status', 'confirmed')
                ->orWhere(function ($q2) {
                    $q2->where('status', 'pending')->where('hold_expires_at', '>', now());
                });
        });

        $rows = $query->get(['participants', 'quantity']);

        $participants = 0;
        $quantity = 0;

        foreach ($rows as $row) {
            if ($row->participants) {
                $participants += array_sum($row->participants);
            }
            if ($row->quantity) {
                $quantity += $row->quantity;
            }
        }

        return ['participants' => $participants, 'quantity' => $quantity];
    }

    public function forCustomer(int $customerId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('customer_id', $customerId)
            ->with(['activity', 'bookingOption', 'schedulePeriod']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }

    public function forActivity(int $activityId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('activity_id', $activityId)
            ->with(['bookingOption', 'customer.user', 'schedulePeriod']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }

    public function forProviderActivities(array $activityIds, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->whereIn('activity_id', $activityIds)
            ->with(['activity', 'bookingOption', 'customer.user', 'schedulePeriod']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['activity', 'bookingOption', 'customer.user', 'schedulePeriod']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['activity'])) {
            $this->applyIdOrUuidFilter($query, 'activity_id', 'activity', $filters['activity']);
        }

        if (! empty($filters['booking_option'])) {
            $this->applyIdOrUuidFilter($query, 'activity_booking_option_id', 'bookingOption', $filters['booking_option']);
        }

        if (! empty($filters['customer'])) {
            $this->applyIdOrUuidFilter($query, 'customer_id', 'customer', $filters['customer']);
        }

        if (! empty($filters['date'])) {
            $query->whereDate('reservation_date', $filters['date']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('reservation_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('reservation_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('uuid', $search);
                if (is_numeric($search)) {
                    $q->orWhere('id', $search);
                }
            });
        }

        $sort = $filters['sort'] ?? 'newest';
        $direction = $sort === 'oldest' ? 'asc' : 'desc';
        // Secondary 'id' tiebreaker — MySQL's default timestamp precision
        // is second-level, so two rows created within the same second
        // (a real occurrence, not just a test artifact — bulk imports,
        // high-traffic booking) would otherwise sort non-deterministically.
        $query->orderBy('created_at', $direction)->orderBy('id', $direction);

        return $query->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }

    /**
     * Shared helper for the three admin filters that accept either a
     * numeric id or a uuid — numeric filters directly on the FK column
     * (no join needed), uuid filters via the relation.
     */
    protected function applyIdOrUuidFilter($query, string $column, string $relation, string $value): void
    {
        if (is_numeric($value)) {
            $query->where($column, $value);
        } else {
            $query->whereHas($relation, fn($q) => $q->where('uuid', $value));
        }
    }
}
