<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Public\AvailabilityQueryRequest;
use App\Http\Resources\Activity\ActivityListResource;
use App\Http\Resources\Activity\ActivityResource;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActivityController extends BaseApiController
{
    public function __construct(
        protected ActivityRepositoryInterface $repository,
        protected AvailabilityService $availabilityService,
    ) {}

    public function index(Request $request)
    {
        $activities = $this->repository->paginatePublished($request->only(['category_id', 'search']));

        return $this->success(ActivityListResource::collection($activities));
    }

    public function show(string $slug)
    {
        $activity = $this->repository->findBySlug($slug);

        if (! $activity || $activity->status !== 'published') {
            return $this->error('Activity not found.', 404);
        }

        return $this->success(new ActivityResource($activity));
    }

    public function availability(AvailabilityQueryRequest $request, string $slug)
    {
        $activity = $this->repository->findBySlug($slug);

        if (! $activity || $activity->status !== 'published') {
            return $this->error('Activity not found.', 404);
        }

        return match ($request->mode()) {
            'range' => $this->resolveRange($request, $activity->id),
            'month' => $this->resolveMonth($request, $activity->id),
            default => $this->resolveSingleDate($request, $activity->id),
        };
    }

    protected function resolveSingleDate(AvailabilityQueryRequest $request, int $activityId)
    {
        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : Carbon::today();

        return $this->success([
            'date' => $date->toDateString(),
            'periods' => $this->availabilityService->resolveForDate($activityId, $date),
        ]);
    }

    protected function resolveRange(AvailabilityQueryRequest $request, int $activityId)
    {
        $from = Carbon::parse($request->input('from_date'));
        $to = Carbon::parse($request->input('to_date'));

        $request->assertRangeNotTooLarge($from, $to);

        return $this->success($this->availabilityService->resolveForRange($activityId, $from, $to));
    }

    protected function resolveMonth(AvailabilityQueryRequest $request, int $activityId)
    {
        [$year, $month] = explode('-', $request->input('month'));

        return $this->success($this->availabilityService->resolveForMonth($activityId, (int) $year, (int) $month));
    }
}
