<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Customer\Reservation\CancelReservationRequest;
use App\Http\Requests\Customer\Reservation\StoreReservationRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\Reservation\ReservationResource;
use App\Models\ActivityBookingOption;
use App\Models\ActivitySchedulePeriod;
use App\Models\Reservation;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReservationController extends BaseApiController
{
    public function __construct(
        protected ReservationService $service,
        protected ActivityRepositoryInterface $activityRepository,
        protected ReservationRepositoryInterface $reservationRepository,
    ) {}

    public function index(PaginationRequest $request)
    {
        $customer = $request->user()->customer;

        $reservations = $this->reservationRepository->forCustomer(
            $customer->id,
            $request->only(['status']),
            $request->perPage()
        );

        return $this->success(ReservationResource::collection($reservations));
    }

    public function store(StoreReservationRequest $request)
    {
        $customer = $request->user()->customer;

        $activity = $this->activityRepository->findByIdOrUuidOrSlug($request->input('activity'));

        if (! $activity || $activity->status !== 'published') {
            return $this->error('Activity not found.', 404);
        }

        $bookingOption = $this->resolveBookingOption($request->input('booking_option'));

        if (! $bookingOption) {
            return $this->error('Booking option not found.', 404);
        }

        $schedulePeriod = null;

        if ($request->filled('schedule_period')) {
            $schedulePeriod = $this->resolveSchedulePeriod($request->input('schedule_period'));

            if (! $schedulePeriod) {
                return $this->error('Schedule period not found.', 404);
            }
        }

        $reservation = $this->service->create(
            $customer,
            $activity,
            $bookingOption,
            $schedulePeriod,
            Carbon::parse($request->input('reservation_date')),
            $request->input('participants'),
            $request->input('quantity'),
            $request->input('duration'),
        );

        $reservation->load(['activity', 'bookingOption', 'schedulePeriod']);

        return $this->created(new ReservationResource($reservation), 'Reservation created successfully. Complete payment before the hold expires.');
    }

    public function show(Request $request, Reservation $reservation)
    {
        $this->abortIfNotOwnReservation($request, $reservation);

        $reservation->load(['activity', 'bookingOption', 'schedulePeriod']);

        return $this->success(new ReservationResource($reservation));
    }

    public function cancel(CancelReservationRequest $request, Reservation $reservation)
    {
        $this->abortIfNotOwnReservation($request, $reservation);

        $reservation = $this->service->cancel($reservation, $request->input('reason'));

        return $this->success(new ReservationResource($reservation), 'Reservation cancelled successfully.');
    }

    /**
     * 404, not 403 — matches this codebase's established convention of
     * not revealing whether a resource exists at all to someone who
     * doesn't own it (same pattern as every child-resource ownership
     * check in Modules 07/08).
     */
    protected function abortIfNotOwnReservation(Request $request, Reservation $reservation): void
    {
        abort_if($reservation->customer_id !== $request->user()->customer->id, 404);
    }

    protected function resolveBookingOption(string $value): ?ActivityBookingOption
    {
        return is_numeric($value)
            ? ActivityBookingOption::find($value)
            : ActivityBookingOption::where('uuid', $value)->first();
    }

    protected function resolveSchedulePeriod(string $value): ?ActivitySchedulePeriod
    {
        return is_numeric($value)
            ? ActivitySchedulePeriod::find($value)
            : ActivitySchedulePeriod::where('uuid', $value)->first();
    }
}
