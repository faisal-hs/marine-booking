<?php

namespace App\Services\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Models\Provider;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\User;
use App\Support\ProviderApi\ErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A provider answers the reviews of THEIR OWN activities. One reply stands per review: it can be edited, and deleting it keeps a record (when, and who)
 * while freeing the review for a new reply. A review an admin hid is not the provider's to answer, and is answered as not found.
 */
class ReviewReplyService
{
    /** A visible review of one of this provider's activities. Anyone else's, a hidden one and a made-up id are all "not found". */
    public function reviewOf(Provider $provider, string $id): Review
    {
        $review = ctype_digit($id)
            ? Review::query()->where('is_hidden', false)->whereHas('activity', fn ($q) => $q->where('provider_id', $provider->id))->find((int) $id)
            : null;

        return $review ?? throw ProviderApiException::notFound('Review not found.');
    }

    public function reply(Provider $provider, Review $review, string $text): ReviewReply
    {
        if ($review->reply()->exists()) {
            throw ProviderApiException::conflict('This review already has a reply. Edit it instead.', ErrorCode::REPLY_ALREADY_EXISTS);
        }

        try {
            return ReviewReply::create(['review_id' => $review->id, 'provider_id' => $provider->id, 'text' => $text, 'is_active' => true]);
        } catch (UniqueConstraintViolationException) {
            // Two replies sent at the same moment: the database lets exactly one stand.
            throw ProviderApiException::conflict('This review already has a reply. Edit it instead.', ErrorCode::REPLY_ALREADY_EXISTS);
        }
    }

    public function edit(Review $review, string $text): ReviewReply
    {
        $reply = $this->standing($review);
        $reply->update(['text' => $text]);

        return $reply;
    }

    public function delete(Review $review, User $by): void
    {
        $this->standing($review)->update(['is_active' => null, 'deleted_at' => now(), 'deleted_by_user_id' => $by->id]);
    }

    private function standing(Review $review): ReviewReply
    {
        return $review->reply()->first() ?? throw ProviderApiException::notFound('This review has no reply.');
    }
}
