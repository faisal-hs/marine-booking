<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\Activity\StoreActivityRequest;
use App\Http\Requests\Provider\Activity\UpdateActivityRequest;
use App\Http\Resources\Activity\ActivityResource;
use App\Models\Activity;
use App\Services\ActivityService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class ActivityController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(protected ActivityService $service) {}

    public function index(Request $request)
    {
        $activities = $request->user()->provider->activities()
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(15);

        return $this->success(ActivityResource::collection($activities));
    }

    public function store(StoreActivityRequest $request)
    {
        $activity = $this->service->create($request->user()->provider, $request->payload());

        return $this->created(new ActivityResource($activity), 'Activity created successfully.');
    }

    public function show(Request $request, Activity $activity)
    {
        $this->authorize('view', $activity);

        return $this->success(new ActivityResource($activity));
    }

    public function update(UpdateActivityRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $activity = $this->service->update($activity, $request->payload());

        return $this->success(new ActivityResource($activity), 'Activity updated successfully.');
    }

    public function destroy(Request $request, Activity $activity)
    {
        $this->authorize('delete', $activity);

        $this->service->delete($activity);

        return $this->noContent('Activity deleted successfully.');
    }

    public function submitForReview(Request $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $activity = $this->service->submitForReview($activity);

        return $this->success(new ActivityResource($activity), 'Activity submitted for review.');
    }
}
