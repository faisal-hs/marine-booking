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
}
