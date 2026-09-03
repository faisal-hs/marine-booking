<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Admin\Review\AdminReviewIndexRequest;
use App\Http\Resources\Review\AdminReviewResource;
use App\Models\Review;
use App\Repositories\Contracts\ReviewRepositoryInterface;
use App\Services\ReviewService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * index/show are permission-gated by 'reviews.view' (admin +
 * support_admin). hide/unhide are gated by the separate 'reviews.moderate'
 * permission (admin only) — matching the same view/mutate split already
 * established for payments.view vs payments.refund. Both mutations
 * delegate entirely to ReviewService, never touching the Eloquent model
 * or the activity rating aggregate directly.
 */
class ReviewController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(
        protected ReviewRepositoryInterface $repository,
        protected ReviewService $service,
    ) {}

    public function index(AdminReviewIndexRequest $request)
    {
        $this->authorize('viewAny', Review::class);

        $reviews = $this->repository->paginateForAdmin($request->filters(), $request->perPage());

        return $this->success(AdminReviewResource::collection($reviews));
    }

    public function show(Review $review)
    {
        $this->authorize('viewAny', Review::class);

        $review->load(['customer.user', 'activity']);

        return $this->success(new AdminReviewResource($review));
    }

    public function hide(Request $request, Review $review)
    {
        $this->authorize('moderate', Review::class);

        $review = $this->service->hide($review, $request->input('reason'));
        $review->load(['customer.user', 'activity']);

        return $this->success(new AdminReviewResource($review), 'Review hidden successfully.');
    }

    public function unhide(Review $review)
    {
        $this->authorize('moderate', Review::class);

        $review = $this->service->unhide($review);
        $review->load(['customer.user', 'activity']);

        return $this->success(new AdminReviewResource($review), 'Review unhidden successfully.');
    }
}
