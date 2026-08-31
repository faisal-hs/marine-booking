<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\Category\CategoryResource;
use App\Repositories\Contracts\CategoryRepositoryInterface;

class CategoryController extends BaseApiController
{
    public function __construct(protected CategoryRepositoryInterface $repository) {}

    public function index()
    {
        $categories = $this->repository->activeOrdered();

        return $this->success(CategoryResource::collection($categories), 'Categories retrieved.');
    }
}
