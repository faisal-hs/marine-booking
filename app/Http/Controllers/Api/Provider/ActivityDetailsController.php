<?php

namespace App\Http\Controllers\Api\Provider;

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

        $requirements = $this->requirementService->sync($activity, $request->input('requirements', []));

        return $this->success(ActivityRequirementResource::collection($requirements), 'Requirements updated successfully.');
    }

    public function syncInclusions(SyncActivityInclusionsRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $inclusions = $this->inclusionService->sync($activity, $request->input('inclusions', []));

        return $this->success(ActivityInclusionResource::collection($inclusions), 'Inclusions updated successfully.');
    }
}
