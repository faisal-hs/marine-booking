<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\Schedule\StoreSchedulePeriodRequest;
use App\Http\Requests\Provider\Schedule\UpdateSchedulePeriodRequest;
use App\Http\Resources\Schedule\SchedulePeriodResource;
use App\Models\Activity;
use App\Models\ActivitySchedulePeriod;
use App\Services\ScheduleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ScheduleController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(protected ScheduleService $service) {}

    public function index(Activity $activity)
    {
        $this->authorize('view', $activity);

        return $this->success(SchedulePeriodResource::collection($activity->schedulePeriods));
    }

    public function store(StoreSchedulePeriodRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $period = $this->service->create($activity, $request->payload());

        return $this->created(new SchedulePeriodResource($period), 'Schedule period created successfully.');
    }

    public function update(UpdateSchedulePeriodRequest $request, Activity $activity, ActivitySchedulePeriod $period)
    {
        $this->authorize('update', $activity);
        $this->abortIfPeriodDoesNotBelongToActivity($activity, $period);

        $period = $this->service->update($period, $request->payload());

        return $this->success(new SchedulePeriodResource($period), 'Schedule period updated successfully.');
    }

    public function destroy(Activity $activity, ActivitySchedulePeriod $period)
    {
        $this->authorize('update', $activity);
        $this->abortIfPeriodDoesNotBelongToActivity($activity, $period);

        $this->service->delete($period);

        return $this->noContent('Schedule period deleted successfully.');
    }

    /**
     * Route-model binding resolves $period globally by uuid, not scoped to
     * $activity — without this check, a provider could pass another
     * activity's period uuid alongside their own activity's uuid and act
     * on a period they don't actually own. Same defense pattern as
     * ActivityImageController (Module 05).
     */
    protected function abortIfPeriodDoesNotBelongToActivity(Activity $activity, ActivitySchedulePeriod $period): void
    {
        abort_if($period->activity_id !== $activity->id, 404);
    }
}
