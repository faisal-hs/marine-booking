<?php

namespace App\Http\Controllers\Web\Public;

use App\Http\Controllers\Controller;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(
        protected ActivityRepositoryInterface $activityRepository,
        protected CategoryRepositoryInterface $categoryRepository,
    ) {}

    public function index(): View
    {
        return view('public.home', [
            'categories' => $this->categoryRepository->activeOrdered(),
            'featuredActivities' => $this->activityRepository->featured(8),
            'topRatedActivities' => $this->activityRepository->topRated(8),
        ]);
    }

    public function about(): View
    {
        return view('public.about');
    }
}
