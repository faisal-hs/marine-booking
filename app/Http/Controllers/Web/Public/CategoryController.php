<?php

namespace App\Http\Controllers\Web\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(
        protected ActivityRepositoryInterface $activityRepository,
        protected CategoryRepositoryInterface $categoryRepository,
    ) {}

    public function show(Request $request, Category $category): View
    {
        abort_if($category->status !== 'active', 404);

        $activities = $this->activityRepository->paginatePublished([
            'category_id' => $category->id,
            'sort' => $request->input('sort', 'newest'),
        ]);

        return view('public.categories.show', [
            'category' => $category,
            'activities' => $activities,
            'categories' => $this->categoryRepository->activeOrdered(),
        ]);
    }
}
