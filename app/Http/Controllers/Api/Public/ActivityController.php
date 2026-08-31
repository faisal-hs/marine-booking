<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\Activity\ActivityListResource;
use App\Http\Resources\Activity\ActivityResource;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Http\Request;

class ActivityController extends BaseApiController
{
    public function __construct(protected ActivityRepositoryInterface $repository) {}

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
}
