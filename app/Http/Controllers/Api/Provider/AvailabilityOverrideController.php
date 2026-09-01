<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\Override\StoreAvailabilityOverrideRequest;
use App\Http\Requests\Provider\Override\UpdateAvailabilityOverrideRequest;
use App\Http\Resources\Override\AvailabilityOverrideResource;
use App\Models\Activity;
use App\Models\ActivityAvailabilityOverride;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class AvailabilityOverrideController extends BaseApiController
{
    use AuthorizesRequests;

    public function index(Activity $activity)
    {
        $this->authorize('view', $activity);

        return $this->success(AvailabilityOverrideResource::collection($activity->availabilityOverrides));
    }

    public function store(StoreAvailabilityOverrideRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $override = $activity->availabilityOverrides()->create($request->payload());

        return $this->created(new AvailabilityOverrideResource($override), 'Availability override created successfully.');
    }

    public function update(UpdateAvailabilityOverrideRequest $request, Activity $activity, ActivityAvailabilityOverride $override)
    {
        $this->authorize('update', $activity);
        $this->abortIfOverrideDoesNotBelongToActivity($activity, $override);

        $override->update($request->payload());

        return $this->success(new AvailabilityOverrideResource($override), 'Availability override updated successfully.');
    }

    public function destroy(Activity $activity, ActivityAvailabilityOverride $override)
    {
        $this->authorize('update', $activity);
        $this->abortIfOverrideDoesNotBelongToActivity($activity, $override);

        $override->delete();

        return $this->noContent('Availability override deleted successfully.');
    }

    protected function abortIfOverrideDoesNotBelongToActivity(Activity $activity, ActivityAvailabilityOverride $override): void
    {
        abort_if($override->activity_id !== $activity->id, 404);
    }
}
