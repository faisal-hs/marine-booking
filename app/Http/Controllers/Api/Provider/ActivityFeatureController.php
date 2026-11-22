<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Api\Provider\Concerns\AppliesActivityEdits;
use App\Http\Requests\Provider\Activity\SyncActivityFeaturesRequest;
use App\Http\Resources\Activity\ActivityDetailResource;
use App\Models\Activity;
use App\Services\ActivityFeatureService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ActivityFeatureController extends BaseApiController
{
    use AppliesActivityEdits;
    use AuthorizesRequests;

    public function __construct(protected ActivityFeatureService $service) {}

    public function sync(SyncActivityFeaturesRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        [, $message] = $this->applyEdit(
            $activity,
            fn () => $this->service->sync($activity, $request->input('features', [])),
            'Features updated successfully.'
        );

        $activity->load(['category', 'images', 'featureValues.featureDefinition']);

        return $this->success(new ActivityDetailResource($activity), $message);
    }
}
