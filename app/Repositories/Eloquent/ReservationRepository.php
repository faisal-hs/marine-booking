<?php

namespace App\Repositories\Eloquent;

use App\Models\Reservation;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ReservationRepository extends BaseRepository implements ReservationRepositoryInterface
{
    public function __construct(Reservation $model)
    {
        parent::__construct($model);
    }

    public function countLiveBookings(?array $activityIds = null, ?int $customerId = null): int
    {
        if ($activityIds === null && $customerId === null) {
            throw new \InvalidArgumentException('Say whose bookings to count: activity ids, a customer id, or both.');
        }

        $query = $this->model->newQuery()->where(function ($q) {
            $q->where(function ($upcoming) {
                $upcoming->where('status', 'confirmed')->whereDate('reservation_date', '>=', today());
            })->orWhere(function ($hold) {
                $hold->where('status', 'pending')->where('hold_expires_at', '>', now());
            });
        });

        if ($activityIds !== null) {
            $query->whereIn('activity_id', $activityIds);
        }

        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }

        return $query->count();
    }

    public function consumedCapacity(int $bookingOptionId, ?int $schedulePeriodId, string $date): array
    {
        $query = $this->activeQuery($bookingOptionId, $schedulePeriodId, $date);

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

    public function windowsOverlapping(int $bookingOptionId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $query = $this->holding($this->model->newQuery()->where('activity_booking_option_id', $bookingOptionId))
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', $to->copy()->utc())
            ->where('ends_at', '>', $from->copy()->utc());

        return $query->get();
    }

    public function holdsWithoutWindow(int $bookingOptionId, string $fromDate, string $toDate): Collection
    {
        $query = $this->holding($this->model->newQuery()->where('activity_booking_option_id', $bookingOptionId))
            ->whereNull('starts_at')->whereNotNull('duration')->whereNotNull('schedule_period_id')
            ->whereBetween('reservation_date', [$fromDate, $toDate]);

        return $query->get();
    }

    /** Only the reservations that hold their place: confirmed, or pending with a hold that has not run out. */
    private function holding($query)
    {
        // 'status' is compared as the raw string stored in the DB: Eloquent's enum cast only affects hydrated model attributes, not how the query builder's where() filters.
        return $query->where(function ($q) {
            $q->where('status', 'confirmed')
                ->orWhere(function ($q2) {
                    $q2->where('status', 'pending')->where('hold_expires_at', '>', now());
                });
        });
    }

    /** The reservations of an option, slot and day that hold a place: confirmed, or pending with a hold that has not run out. */
    private function activeQuery(int $bookingOptionId, ?int $schedulePeriodId, string $date)
    {
        $query = $this->model->newQuery()
            ->where('activity_booking_option_id', $bookingOptionId)
            ->where('reservation_date', $date);

        if ($schedulePeriodId !== null) {
            $query->where('schedule_period_id', $schedulePeriodId);
        } else {
            $query->whereNull('schedule_period_id');
        }

        return $this->holding($query);
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
            $query->whereHas($relation, fn ($q) => $q->where('uuid', $value));
        }
    }
}
