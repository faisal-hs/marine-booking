<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Review;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\ReviewRepositoryInterface;
use Illuminate\Validation\ValidationException;

/**
 * Owns the two confirmed business rules for reviews: verified purchase
 * (a completed reservation is required) and one review per activity
 * total (not per reservation). Also owns keeping Activity's denormalized
 * average_rating/reviews_count aggregate in sync — every mutation path
 * (create/update/delete/hide/unhide) recalculates it the same way, from
 * ONLY visible reviews, so the aggregate can never drift from what's
 * actually publicly shown.
 */
class ReviewService
{
    public function __construct(
        protected ReviewRepositoryInterface $repository,
        protected ActivityRepositoryInterface $activityRepository,
    ) {}

    public function create(Customer $customer, Activity $activity, int $rating, ?string $comment): Review
    {
        $completedReservation = Reservation::where('customer_id', $customer->id)
            ->where('activity_id', $activity->id)
            ->where('status', 'completed')
            ->first();

        if (! $completedReservation) {
            throw ValidationException::withMessages([
                'activity' => ['You can only review an activity after completing a reservation for it.'],
            ]);
        }

        if ($this->repository->existsForCustomerAndActivity($customer->id, $activity->id)) {
            throw ValidationException::withMessages([
                'activity' => ['You have already reviewed this activity.'],
            ]);
        }

        $review = $this->repository->create([
            'customer_id' => $customer->id,
            'activity_id' => $activity->id,
            'reservation_id' => $completedReservation->id,
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $this->recalculateActivityRating($activity);

        return $review;
    }

    public function update(Review $review, int $rating, ?string $comment): Review
    {
        $review = $this->repository->update($review, [
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $this->recalculateActivityRating($review->activity);

        return $review;
    }

    public function delete(Review $review): void
    {
        $activity = $review->activity;

        $this->repository->delete($review);

        $this->recalculateActivityRating($activity->fresh());
    }

    /**
     * Reversible admin moderation — "admin can hide/remove later" per the
     * confirmed design. The review record stays intact for audit.
     */
    public function hide(Review $review, ?string $reason): Review
    {
        $review = $this->repository->update($review, [
            'is_hidden' => true,
            'hidden_reason' => $reason,
            'hidden_at' => now(),
        ]);

        $this->recalculateActivityRating($review->activity);

        return $review;
    }

    public function unhide(Review $review): Review
    {
        $review = $this->repository->update($review, [
            'is_hidden' => false,
            'hidden_reason' => null,
            'hidden_at' => null,
        ]);

        $this->recalculateActivityRating($review->activity);

        return $review;
    }

    protected function recalculateActivityRating(Activity $activity): void
    {
        $stats = $activity->visibleReviews()
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as review_count')
            ->first();

        $this->activityRepository->update($activity, [
            'average_rating' => round((float) ($stats->avg_rating ?? 0), 2),
            'reviews_count' => (int) ($stats->review_count ?? 0),
        ]);
    }
}
