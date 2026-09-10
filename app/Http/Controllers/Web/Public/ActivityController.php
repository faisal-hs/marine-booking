<?php

namespace App\Http\Controllers\Web\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Public\ActivitySearchRequest;
use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\ReviewRepositoryInterface;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function __construct(
        protected ActivityRepositoryInterface $activityRepository,
        protected CategoryRepositoryInterface $categoryRepository,
        protected ReviewRepositoryInterface $reviewRepository,
    ) {}

    public function index(ActivitySearchRequest $request): View
    {
        $activities = $this->activityRepository->paginatePublished($request->filters());

        return view('public.activities.index', [
            'activities' => $activities,
            'categories' => $this->categoryRepository->activeOrdered(),
            'filters' => $request->filters(),
        ]);
    }

    public function show(string $activity): View
    {
        $activityModel = $this->activityRepository->findByIdOrUuidOrSlug($activity);

        abort_if(! $activityModel || $activityModel->status !== 'published', 404);

        $activityModel->load([
            'category',
            'provider',
            'images',
            'featureValues.featureDefinition',
            'options',
            'requirements',
            'inclusions',
            'schedulePeriods',
            'bookingOptions' => fn($q) => $q->where('is_active', true),
            'bookingOptions.pricingRules' => fn($q) => $q->where('is_active', true),
        ]);

        $reviews = $this->reviewRepository->forActivity($activityModel->id);

        return view('public.activities.show', [
            'activity' => $activityModel,
            'reviews' => $reviews,
        ]);
    }
}
