<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\Activity\SyncActivityFeaturesRequest;
use App\Http\Resources\Activity\ActivityResource;
use App\Models\Activity;
use App\Services\ActivityFeatureService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ActivityFeatureController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(protected ActivityFeatureService $service) {}

    public function sync(SyncActivityFeaturesRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $this->service->sync($activity, $request->input('features', []));

        $activity->load(['category', 'images', 'featureValues.featureDefinition']);

        return $this->success(new ActivityResource($activity), 'Features updated successfully.');
    }
}
