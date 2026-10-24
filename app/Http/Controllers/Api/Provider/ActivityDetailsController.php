<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\Provider\Concerns\GuardsLiveActivityContent;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\Activity\SyncActivityInclusionsRequest;
use App\Http\Requests\Provider\Activity\SyncActivityOptionsRequest;
use App\Http\Requests\Provider\Activity\SyncActivityRequirementsRequest;
use App\Http\Resources\ActivityInclusion\ActivityInclusionResource;
use App\Http\Resources\ActivityOption\ActivityOptionResource;
use App\Http\Resources\ActivityRequirement\ActivityRequirementResource;
use App\Models\Activity;
use App\Services\ActivityInclusionService;
use App\Services\ActivityOptionService;
use App\Services\ActivityRequirementService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ActivityDetailsController extends BaseApiController
{
    use AuthorizesRequests;
    use GuardsLiveActivityContent;

    public function __construct(
        protected ActivityOptionService $optionService,
        protected ActivityRequirementService $requirementService,
        protected ActivityInclusionService $inclusionService,
    ) {}

    public function syncOptions(SyncActivityOptionsRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $options = $this->optionService->sync($activity, $request->input('options', []));

        return $this->success(ActivityOptionResource::collection($options), 'Options updated successfully.');
    }

    public function syncRequirements(SyncActivityRequirementsRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        [$requirements, $message] = $this->editWithReviewGuard(
            $activity,
            fn() => $this->requirementService->sync($activity, $request->input('requirements', [])),
            'Requirements updated successfully.'
        );

        return $this->success(ActivityRequirementResource::collection($requirements), $message);
    }

    public function syncInclusions(SyncActivityInclusionsRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        [$inclusions, $message] = $this->editWithReviewGuard(
            $activity,
            fn() => $this->inclusionService->sync($activity, $request->input('inclusions', [])),
            'Inclusions updated successfully.'
        );

        return $this->success(ActivityInclusionResource::collection($inclusions), $message);
    }
}
