<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\BookingOption\StoreBookingOptionRequest;
use App\Http\Requests\Provider\BookingOption\UpdateBookingOptionRequest;
use App\Http\Resources\BookingOption\ActivityBookingOptionResource;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Services\ActivityBookingOptionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ActivityBookingOptionController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(protected ActivityBookingOptionService $service) {}

    public function index(Activity $activity)
    {
        $this->authorize('view', $activity);

        $options = $activity->bookingOptions()->with('pricingRules')->get();

        return $this->success(ActivityBookingOptionResource::collection($options));
    }

    public function store(StoreBookingOptionRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $option = $this->service->create($activity, $request->payload());

        return $this->created(new ActivityBookingOptionResource($option), 'Booking option created successfully.');
    }

    public function show(Activity $activity, ActivityBookingOption $bookingOption)
    {
        $this->authorize('view', $activity);
        $this->abortIfOptionDoesNotBelongToActivity($activity, $bookingOption);

        $bookingOption->load('pricingRules');

        return $this->success(new ActivityBookingOptionResource($bookingOption));
    }

    public function update(UpdateBookingOptionRequest $request, Activity $activity, ActivityBookingOption $bookingOption)
    {
        $this->authorize('update', $activity);
        $this->abortIfOptionDoesNotBelongToActivity($activity, $bookingOption);

        $bookingOption = $this->service->update($bookingOption, $request->payload());

        return $this->success(new ActivityBookingOptionResource($bookingOption), 'Booking option updated successfully.');
    }

    public function destroy(Activity $activity, ActivityBookingOption $bookingOption)
    {
        $this->authorize('update', $activity);
        $this->abortIfOptionDoesNotBelongToActivity($activity, $bookingOption);

        $this->service->delete($bookingOption);

        return $this->noContent('Booking option deleted successfully.');
    }

    /**
     * Same defense pattern as ScheduleController/AvailabilityOverrideController
     * — route-model binding resolves $bookingOption globally by uuid, not
     * scoped to $activity, so a provider could otherwise pass another of
     * their own activities' option uuid alongside a different activity's
     * uuid in the URL.
     */
    protected function abortIfOptionDoesNotBelongToActivity(Activity $activity, ActivityBookingOption $bookingOption): void
    {
        abort_if($bookingOption->activity_id !== $activity->id, 404);
    }
}
