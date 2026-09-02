<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReservationRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Total participants/quantity currently held against this exact
     * (booking option, schedule period, date) slot — counting only
     * 'confirmed' reservations and 'pending' reservations whose hold
     * hasn't expired yet. This is a LIVE check: an expired pending hold
     * stops counting the instant its hold_expires_at passes, with no
     * dependency on a scheduled job having already flipped its status.
     *
     * @return array{participants: int, quantity: int}
     */
    public function consumedCapacity(int $bookingOptionId, ?int $schedulePeriodId, string $date): array;

    public function forCustomer(int $customerId, array $filters, int $perPage = 15): LengthAwarePaginator;

    public function forActivity(int $activityId, array $filters, int $perPage = 15): LengthAwarePaginator;

    public function forProviderActivities(array $activityIds, array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Unrestricted admin listing across every reservation, with the full
     * filter set: status, activity, booking option, customer, date/date
     * range, free-text search (uuid or numeric id), and sort order.
     */
    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator;
}
