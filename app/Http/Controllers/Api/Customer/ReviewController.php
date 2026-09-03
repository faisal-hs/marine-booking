<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Customer\Review\StoreReviewRequest;
use App\Http\Requests\Customer\Review\UpdateReviewRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\Review\ReviewResource;
use App\Models\Review;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Services\ReviewService;
use Illuminate\Http\Request;

class ReviewController extends BaseApiController
{
    public function __construct(
        protected ReviewService $service,
        protected ActivityRepositoryInterface $activityRepository,
    ) {}

    public function index(PaginationRequest $request)
    {
        $customer = $request->user()->customer;

        $reviews = Review::where('customer_id', $customer->id)
            ->with('activity')
            ->latest()
            ->paginate($request->perPage(), ['*'], 'current_page')
            ->withQueryString();

        return $this->success(ReviewResource::collection($reviews));
    }

    public function store(StoreReviewRequest $request)
    {
        $customer = $request->user()->customer;

        $activity = $this->activityRepository->findByIdOrUuidOrSlug($request->input('activity'));

        if (! $activity) {
            return $this->error('Activity not found.', 404);
        }

        $review = $this->service->create($customer, $activity, $request->input('rating'), $request->input('comment'));

        $review->load('activity');

        return $this->created(new ReviewResource($review), 'Review submitted successfully.');
    }

    public function update(UpdateReviewRequest $request, Review $review)
    {
        abort_if($review->customer_id !== $request->user()->customer->id, 404);

        $review = $this->service->update($review, $request->input('rating'), $request->input('comment'));
        $review->load('activity');

        return $this->success(new ReviewResource($review), 'Review updated successfully.');
    }

    public function destroy(Request $request, Review $review)
    {
        abort_if($review->customer_id !== $request->user()->customer->id, 404);

        $this->service->delete($review);

        return $this->noContent('Review deleted successfully.');
    }
}
