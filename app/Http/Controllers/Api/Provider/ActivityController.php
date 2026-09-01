<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\Provider\Activity\StoreActivityRequest;
use App\Http\Requests\Provider\Activity\UpdateActivityRequest;
use App\Http\Resources\Activity\ActivityResource;
use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Services\ActivityFeatureService;
use App\Services\ActivityImageService;
use App\Services\ActivityInclusionService;
use App\Services\ActivityOptionService;
use App\Services\ActivityRequirementService;
use App\Services\ActivityService;
use App\Services\ScheduleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(
        protected ActivityService $service,
        protected ActivityRepositoryInterface $repository,
        protected ActivityImageService $imageService,
        protected ActivityFeatureService $featureService,
        protected ActivityOptionService $optionService,
        protected ActivityRequirementService $requirementService,
        protected ActivityInclusionService $inclusionService,
        protected ScheduleService $scheduleService,
    ) {}

    public function index(PaginationRequest $request)
    {
        $activities = $this->repository->paginateForProvider(
            $request->user()->provider->id,
            $request->only(['status']),
            $request->perPage()
        );

        return $this->success(ActivityResource::collection($activities));
    }

    /**
     * Combined create: base activity fields plus optional images, features,
     * options, requirements, inclusions, and schedule — all in one request,
     * one transaction. Every sub-resource is optional; omitting one simply
     * means that part isn't touched (e.g. create the activity with no
     * schedule yet, add it later via the dedicated schedule endpoints, or
     * via a subsequent combined update carrying just 'schedule').
     */
    public function store(StoreActivityRequest $request)
    {
        $activity = DB::transaction(function () use ($request) {
            $activity = $this->service->create($request->user()->provider, $request->payload());

            $this->applyCombinedExtras($request, $activity);

            return $activity;
        });

        $activity->load(['category', 'images', 'featureValues.featureDefinition', 'options', 'requirements', 'inclusions', 'schedulePeriods', 'availabilityOverrides']);

        return $this->created(new ActivityResource($activity), 'Activity created successfully.');
    }

    public function show(Request $request, Activity $activity)
    {
        $this->authorize('view', $activity);

        $activity->load(['category', 'images', 'featureValues.featureDefinition', 'options', 'requirements', 'inclusions', 'schedulePeriods', 'availabilityOverrides']);

        return $this->success(new ActivityResource($activity));
    }

    /**
     * Combined update: identical shape to store(), but every field
     * (including every sub-resource) is optional — a client may send only
     * the single value that's changing (e.g. just {"title": "New Title"}),
     * or a fuller payload replacing images/options/requirements/inclusions/
     * schedule in the same call as base field changes.
     */
    public function update(UpdateActivityRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $activity = DB::transaction(function () use ($request, $activity) {
            $activity = $this->service->update($activity, $request->payload());

            $this->applyCombinedExtras($request, $activity);

            return $activity;
        });

        $activity->load(['category', 'images', 'featureValues.featureDefinition', 'options', 'requirements', 'inclusions', 'schedulePeriods', 'availabilityOverrides']);

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

    /**
     * Delegates every optional sub-resource to its own already-tested
     * Service — this Controller never touches Eloquent directly for any
     * of these, it just orchestrates which existing Services run, inside
     * the single outer transaction store()/update() already opened.
     *
     * 'schedule' REPLACES the activity's entire schedule when present
     * (matching ActivityOptionService/etc.'s existing full-replace
     * semantics for options/requirements/inclusions) — it is not a partial
     * per-period patch. Omitting 'schedule' entirely leaves the existing
     * schedule untouched.
     */
    protected function applyCombinedExtras(StoreActivityRequest|UpdateActivityRequest $request, Activity $activity): void
    {
        if ($request->hasFile('images')) {
            $this->imageService->upload($activity, $request->file('images'));
        }

        if ($request->filled('features')) {
            $this->featureService->sync($activity, $request->input('features', []));
        }

        if ($request->has('options')) {
            $this->optionService->sync($activity, $request->input('options', []));
        }

        if ($request->has('requirements')) {
            $this->requirementService->sync($activity, $request->input('requirements', []));
        }

        if ($request->has('inclusions')) {
            $this->inclusionService->sync($activity, $request->input('inclusions', []));
        }

        if ($request->has('schedule')) {
            $activity->schedulePeriods()->delete();

            foreach ($request->scheduleItems() as $item) {
                $this->scheduleService->create($activity, $item);
            }
        }
    }
}
