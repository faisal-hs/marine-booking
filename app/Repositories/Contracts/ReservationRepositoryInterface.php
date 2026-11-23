<?php

namespace App\Repositories\Contracts;

use App\Models\Reservation;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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

    /**
     * The reservations of this option that hold TIME between `$from` and `$to` (a window each: confirmed, or pending with a hold that has not run out, the same ones consumedCapacity
     * counts).
     *
     * @return Collection<int, Reservation>
     */
    public function windowsOverlapping(int $bookingOptionId, CarbonInterface $from, CarbonInterface $to): Collection;

    /**
     * The reservations of this option that hold time but have no window of their own: a booking by the hour made as a number of hours, which holds its departure from its start.
     * Only those of departures on the days from `$fromDate` to `$toDate`.
     *
     * @return Collection<int, Reservation>
     */
    public function holdsWithoutWindow(int $bookingOptionId, string $fromDate, string $toDate): Collection;

    /**
     * Bookings that still matter to somebody: CONFIRMED and still ahead (the date has not
     * passed), or PENDING with a hold that has not expired (a customer may be paying right
     * now). Cancelled, expired and completed ones, and confirmed ones already in the past,
     * are history. Used to refuse deleting an account or activity that is mid-booking.
     *
     * @param  int[]|null  $activityIds  only bookings for these activities
     * @param  int|null  $customerId  only this customer's bookings
     */
    public function countLiveBookings(?array $activityIds = null, ?int $customerId = null): int;

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
