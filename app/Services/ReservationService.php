<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Events\ReservationCancelled;
use App\Events\ReservationConfirmed;
use App\Events\ReservationCreated;
use App\Events\ReservationExpired;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivitySchedulePeriod;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationCapacityLock;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single orchestrator for the reservation lifecycle. Deliberately
 * calls AvailabilityService and ActivityPricingService rather than
 * duplicating either's logic — this Service's own job is strictly:
 * ownership/cross-entity validation, the capacity race-condition guard,
 * and persisting the frozen price snapshot. It never recalculates price
 * after creation (spec §32).
 *
 * Notification dispatch is entirely decoupled — this class only ever
 * fires plain domain events (event(new ReservationCreated($reservation)))
 * and has zero knowledge of channels, recipients, or Notification
 * classes. See app/Listeners/Notifications for what actually happens
 * when each event fires.
 */
class ReservationService
{
    public function __construct(
        protected ReservationRepositoryInterface $repository,
        protected AvailabilityService $availabilityService,
        protected ActivityPricingService $pricingService,
    ) {}

    public function create(
        Customer $customer,
        Activity $activity,
        ActivityBookingOption $bookingOption,
        ?ActivitySchedulePeriod $schedulePeriod,
        Carbon $date,
        ?array $participants = null,
        ?int $quantity = null,
        ?int $duration = null,
    ): Reservation {
        if ($bookingOption->activity_id !== $activity->id) {
            throw ValidationException::withMessages([
                'booking_option' => ['This booking option does not belong to the given activity.'],
            ]);
        }

        if ($schedulePeriod && $schedulePeriod->activity_id !== $activity->id) {
            throw ValidationException::withMessages([
                'schedule_period' => ['This schedule period does not belong to the given activity.'],
            ]);
        }

        if ($date->startOfDay()->isBefore(Carbon::today())) {
            throw ValidationException::withMessages([
                'reservation_date' => ['The reservation date cannot be in the past.'],
            ]);
        }

        if (! $this->availabilityService->isBookable($activity->id, $schedulePeriod?->id, $date)) {
            throw ValidationException::withMessages([
                'reservation_date' => ['This activity is not available on the selected date and period.'],
            ]);
        }

        // Priced BEFORE the capacity lock is taken — a pricing failure
        // (missing rule, inactive option, etc.) should never hold a lock.
        $quote = $this->pricingService->quote($activity, $bookingOption, $schedulePeriod, $participants, $quantity, $duration);

        $reservation = DB::transaction(function () use ($activity, $bookingOption, $customer, $schedulePeriod, $date, $participants, $quantity, $duration, $quote) {
            // Guarantees a lockable row exists even for the very first
            // reservation attempt on this exact slot — see
            // ReservationCapacityLock's class doc comment for why a plain
            // lockForUpdate() on the reservations table alone can't do this.
            $lock = ReservationCapacityLock::firstOrCreate([
                'activity_booking_option_id' => $bookingOption->id,
                'schedule_period_id' => $schedulePeriod?->id ?? 0,
                'reservation_date' => $date->toDateString(),
            ]);

            ReservationCapacityLock::whereKey($lock->id)->lockForUpdate()->first();

            $this->assertCapacityAvailable($bookingOption, $schedulePeriod, $date, $participants, $quantity);

            return $this->repository->create([
                'activity_id' => $activity->id,
                'activity_booking_option_id' => $bookingOption->id,
                'customer_id' => $customer->id,
                'schedule_period_id' => $schedulePeriod?->id,
                'reservation_date' => $date->toDateString(),
                'participants' => $participants,
                'quantity' => $quantity,
                'duration' => $duration,
                'status' => ReservationStatus::Pending->value,
                'hold_expires_at' => now()->addMinutes((int) config('reservations.hold_minutes')),
                'pricing_model' => $quote['pricing_model'],
                'currency' => $quote['currency'],
                'subtotal' => $quote['subtotal'],
                'discount' => $quote['discount'],
                'tax' => $quote['tax'],
                'total' => $quote['total'],
                'pricing_breakdown' => $quote['breakdown'],
            ]);
        });

        event(new ReservationCreated($reservation));

        return $reservation;
    }

    /**
     * Runs INSIDE the transaction, after the capacity lock row has been
     * acquired — this is what actually prevents two concurrent bookings
     * from both reading "capacity available" before either commits.
     */
    protected function assertCapacityAvailable(
        ActivityBookingOption $option,
        ?ActivitySchedulePeriod $period,
        Carbon $date,
        ?array $participants,
        ?int $quantity,
    ): void {
        $consumed = $this->repository->consumedCapacity($option->id, $period?->id, $date->toDateString());

        if ($participants !== null && $option->max_participants !== null) {
            $requested = array_sum($participants);

            if ($consumed['participants'] + $requested > $option->max_participants) {
                throw ValidationException::withMessages([
                    'participants' => ['Not enough remaining capacity for this date.'],
                ]);
            }
        }

        if ($quantity !== null && $option->max_quantity !== null) {
            if ($consumed['quantity'] + $quantity > $option->max_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['Not enough remaining capacity for this date.'],
                ]);
            }
        }
    }

    /**
     * The Module 10 (Payments) integration seam — only a verified
     * successful payment should ever call this. Idempotent against
     * double-confirmation and refuses anything whose hold already expired.
     */
    public function confirmPayment(Reservation $reservation): Reservation
    {
        if ($reservation->status !== ReservationStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Only a pending reservation can be confirmed.'],
            ]);
        }

        if ($reservation->hold_expires_at !== null && $reservation->hold_expires_at->isPast()) {
            throw ValidationException::withMessages([
                'status' => ['This reservation hold has expired and can no longer be confirmed.'],
            ]);
        }

        $reservation->update([
            'status' => ReservationStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $reservation = $reservation->fresh();

        event(new ReservationConfirmed($reservation));

        return $reservation;
    }

    public function cancel(Reservation $reservation, ?string $reason = null): Reservation
    {
        if (in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::Expired, ReservationStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'status' => ['This reservation cannot be cancelled from its current status.'],
            ]);
        }

        $reservation->update([
            'status' => ReservationStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $reservation = $reservation->fresh();

        event(new ReservationCancelled($reservation));

        return $reservation;
    }

    /**
     * Data-hygiene only — NOT load-bearing for correctness. The live
     * capacity check in ReservationRepository::consumedCapacity() already
     * ignores an expired pending hold the instant it expires, regardless
     * of whether this has run. This just makes the stored status honest
     * for reporting/admin visibility.
     *
     * Restructured from a single bulk UPDATE to fetch-then-update-each:
     * firing a per-reservation ReservationExpired event (so the customer
     * and admins actually get notified) requires knowing WHICH rows were
     * affected, which a bulk UPDATE alone cannot provide.
     */
    public function expireStaleHolds(): int
    {
        $staleReservations = Reservation::where('status', ReservationStatus::Pending->value)
            ->where('hold_expires_at', '<=', now())
            ->get();

        foreach ($staleReservations as $reservation) {
            $reservation->update(['status' => ReservationStatus::Expired->value]);
            event(new ReservationExpired($reservation->fresh()));
        }

        return $staleReservations->count();
    }
}
