<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only, matching the JSON admin API (Api\Admin\ReservationController)
 * exactly in behavior — deliberately reuses the SAME ReservationPolicy and
 * the SAME ReservationRepository::paginateForAdmin() rather than
 * duplicating either. No store/update/destroy here or anywhere else for
 * this resource.
 */
class ReservationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected ReservationRepositoryInterface $repository) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Reservation::class);

        $filters = $request->only([
            'status',
            'activity',
            'booking_option',
            'customer',
            'date',
            'date_from',
            'date_to',
            'search',
            'sort',
        ]);

        $reservations = $this->repository->paginateForAdmin($filters);

        return view('admin.reservations.index', [
            'reservations' => $reservations,
            'filters' => $filters,
        ]);
    }

    public function show(Reservation $reservation): View
    {
        $this->authorize('view', $reservation);

        $reservation->load(['activity', 'bookingOption', 'customer.user', 'schedulePeriod']);

        return view('admin.reservations.show', ['reservation' => $reservation]);
    }
}
