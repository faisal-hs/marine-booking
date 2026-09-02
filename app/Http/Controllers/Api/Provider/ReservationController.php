<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\Reservation\ReservationResource;
use App\Models\Activity;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ReservationController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(protected ReservationRepositoryInterface $repository) {}

    /**
     * Reservations across ALL of the provider's own activities.
     */
    public function index(PaginationRequest $request)
    {
        $provider = $request->user()->provider;
        $activityIds = Activity::where('provider_id', $provider->id)->pluck('id')->all();

        $reservations = $this->repository->forProviderActivities(
            $activityIds,
            $request->only(['status']),
            $request->perPage()
        );

        return $this->success(ReservationResource::collection($reservations));
    }

    /**
     * Reservations scoped to one specific activity — reuses ActivityPolicy's
     * existing ownership check rather than duplicating it.
     */
    public function forActivity(PaginationRequest $request, Activity $activity)
    {
        $this->authorize('view', $activity);

        $reservations = $this->repository->forActivity(
            $activity->id,
            $request->only(['status']),
            $request->perPage()
        );

        return $this->success(ReservationResource::collection($reservations));
    }
}
