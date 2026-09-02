<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Admin\Reservation\AdminReservationIndexRequest;
use App\Http\Resources\Reservation\AdminReservationResource;
use App\Models\Reservation;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Read-only by design — no store/update/destroy exist here, and none
 * should be added. Reservation lifecycle mutations (create, cancel,
 * confirmPayment) live exclusively in ReservationService, called only
 * from the customer-facing API and, later, Module 10's payment webhook.
 * This Controller never touches ReservationService at all.
 */
class ReservationController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(protected ReservationRepositoryInterface $repository) {}

    public function index(AdminReservationIndexRequest $request)
    {
        $this->authorize('viewAny', Reservation::class);

        $reservations = $this->repository->paginateForAdmin($request->filters(), $request->perPage());

        return $this->success(AdminReservationResource::collection($reservations));
    }

    public function show(Reservation $reservation)
    {
        $this->authorize('view', $reservation);

        $reservation->load(['activity', 'bookingOption', 'customer.user', 'schedulePeriod']);

        return $this->success(new AdminReservationResource($reservation));
    }
}
