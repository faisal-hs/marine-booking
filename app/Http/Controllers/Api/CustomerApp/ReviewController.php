<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\CustomerApp\CreateReviewRequest;
use App\Models\Activity;
use App\Models\Review;
use App\Services\CustomerApp\ActivityCatalog;
use App\Services\ReviewService;
use App\Support\ProviderApi\ErrorCode;

/** A customer reviewing a trip they took. The customer is always the one signed in; the booking must be theirs, completed, and for this activity. */
class ReviewController extends CustomerAppController
{
    public function __construct(private readonly ReviewService $reviews, private readonly ActivityCatalog $catalog) {}

    public function store(CreateReviewRequest $request, string $id)
    {
        $customer = $this->customer($request);

        // A completed trip can be reviewed even if the activity has since been taken down, so this is not limited to what customers can see now.
        $activity = ctype_digit($id) ? Activity::find((int) $id) : null;
        // Someone else's booking, a booking of another activity and a made-up one are all answered the same way.
        $booking = $activity ? $customer->reservations()->where('activity_id', $activity->id)->find($request->integer('booking_id')) : null;

        if (! $activity || ! $booking) {
            throw ProviderApiException::notFound('Booking not found.');
        }

        if ($booking->status->value !== 'completed') {
            throw new ProviderApiException('You can review a trip once it has taken place.', 422, ErrorCode::REVIEW_NOT_ALLOWED);
        }

        if (Review::where('customer_id', $customer->id)->where(fn ($q) => $q->where('reservation_id', $booking->id)->orWhere('activity_id', $activity->id))->exists()) {
            throw new ProviderApiException('You have already reviewed this activity.', 409, ErrorCode::REVIEW_NOT_ALLOWED);
        }

        $review = $this->reviews->createForReservation($customer, $activity, $booking, $request->integer('rating'), $request->validated('text'));

        return $this->created($this->catalog->review($review->load(['customer.user', 'reply'])), 'Review added');
    }
}
