<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\Activity\ActivityListResource;
use App\Http\Resources\Category\CategoryResource;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;

class CategoryController extends BaseApiController
{
    public function __construct(
        protected CategoryRepositoryInterface $repository,
        protected ActivityRepositoryInterface $activityRepository,
    ) {}

    public function index()
    {
        $categories = $this->repository->activeOrdered();

        return $this->success(CategoryResource::collection($categories), 'Categories retrieved.');
    }

    /**
     * Accepts numeric id, UUID, or slug interchangeably. Returns the
     * category plus every published activity belonging to it.
     */
    public function show(string $category)
    {
        $categoryModel = $this->repository->findByIdOrUuidOrSlug($category);

        if (! $categoryModel || $categoryModel->status !== 'active') {
            return $this->error('Category not found.', 404);
        }

        $activities = $this->activityRepository->paginatePublished(['category_id' => $categoryModel->id], 100);

        $data = (new CategoryResource($categoryModel))->toArray(request());
        $data['activities'] = ActivityListResource::collection($activities->items());

        return $this->success($data, 'Category retrieved.');
    }
}
