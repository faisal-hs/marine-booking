<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Repositories\Contracts\ReviewRepositoryInterface;
use App\Services\ReviewService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ReviewRepositoryInterface $repository,
        protected ReviewService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Review::class);

        $filters = $request->only(['activity', 'sort']);
        if ($request->has('is_hidden')) {
            $filters['is_hidden'] = $request->boolean('is_hidden');
        }

        $reviews = $this->repository->paginateForAdmin($filters);

        return view('admin.reviews.index', ['reviews' => $reviews, 'filters' => $filters]);
    }

    public function show(Review $review): View
    {
        $this->authorize('viewAny', Review::class);

        $review->load(['customer.user', 'activity']);

        return view('admin.reviews.show', ['review' => $review]);
    }

    public function hide(Request $request, Review $review): RedirectResponse
    {
        $this->authorize('moderate', Review::class);

        $this->service->hide($review, $request->input('reason'));

        return back()->with('status', __('admin.reviews.hide_success'));
    }

    public function unhide(Review $review): RedirectResponse
    {
        $this->authorize('moderate', Review::class);

        $this->service->unhide($review);

        return back()->with('status', __('admin.reviews.unhide_success'));
    }
}
