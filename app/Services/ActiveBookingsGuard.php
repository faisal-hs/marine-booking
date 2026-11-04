<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Provider;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Validation\ValidationException;

/**
 * "Not while somebody is mid-booking." Deleting a provider, a customer or an activity
 * that still has a live booking would strand a customer who has paid (or is paying
 * right now), and refunds are not automatic. So the delete is refused, with the
 * number of bookings in the message, until they have been cancelled or completed.
 *
 * A live booking is one that is confirmed and still ahead, or pending with an unexpired
 * hold (see ReservationRepositoryInterface::countLiveBookings).
 */
class ActiveBookingsGuard
{
    public function __construct(protected ReservationRepositoryInterface $reservations) {}

    public function assertNoneForActivity(Activity $activity): void
    {
        $this->refuseIfAny($this->reservations->countLiveBookings([$activity->id]), 'admin.guards.activity_has_bookings');
    }

    public function assertNoneForProvider(Provider $provider): void
    {
        // Trashed activities are included on purpose: a deleted listing must not hide a live booking.
        $activityIds = $provider->activities()->withTrashed()->pluck('id')->all();

        $this->refuseIfAny($activityIds === [] ? 0 : $this->reservations->countLiveBookings($activityIds), 'admin.guards.provider_has_bookings');
    }

    public function assertNoneForCustomer(Customer $customer): void
    {
        $this->refuseIfAny($this->reservations->countLiveBookings(null, $customer->id), 'admin.guards.customer_has_bookings');
    }

    private function refuseIfAny(int $count, string $messageKey): void
    {
        if ($count > 0) {
            throw ValidationException::withMessages([
                'delete' => [__($messageKey, ['count' => $count])],
            ]);
        }
    }
}
