<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Providers answering reviews: about four visible reviews in ten have a reply from the provider of the activity, written one to five days after the
 * review. About one reply in fourteen was later deleted (kept as a record, with who and when) and half of those were answered again. A hidden review
 * is never answered, and at most one reply stands per review. Running it again adds nothing.
 * Volume at scale 1: about a third of the seeded reviews.
 * Prerequisites: BulkReviewSeeder.
 */
class BulkReviewReplySeeder extends BulkSeeder
{
    private const REPLIES = ['Thank you so much for your kind words, we hope to welcome you again soon!', 'We are glad you enjoyed the trip. See you next time.', 'Thank you for the feedback, it helps us improve.',
        'We appreciate you taking the time to review. Looking forward to seeing you again.', 'Thanks for joining us! The crew loved having you on board.'];

    protected function seed(): void
    {
        $this->needs('reviews', 'BulkReviewSeeder');

        $answered = array_flip(DB::table('review_replies')->distinct()->pluck('review_id')->all());

        DB::table('reviews as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->join('providers as p', 'p.id', '=', 'a.provider_id')->where('r.is_hidden', 0)
            ->orderBy('r.id')->select(['r.id', 'r.created_at', 'p.id as provider_id', 'p.user_id as provider_user'])
            ->chunk(2000, function ($reviews) use ($answered) {
                $this->insert('review_replies', (function () use ($reviews, $answered) {
                    foreach ($reviews as $review) {
                        if (isset($answered[$review->id]) || ! $this->stable("review-reply:{$review->id}", 40)) {
                            continue;
                        }

                        $wrote = Carbon::parse($review->created_at)->addDays($this->rand(1, 5))->addMinutes($this->rand(0, 1439));
                        if ($wrote->greaterThan($this->now)) {
                            continue;
                        }

                        if ($this->stable("review-reply-deleted:{$review->id}", 7)) {
                            $deleted = $wrote->copy()->addDays($this->rand(1, 10))->min($this->now);

                            yield $this->row($review, $wrote, false, $deleted);

                            if ($this->chance(50) && $deleted->lessThan($this->now)) {
                                yield $this->row($review, $deleted->copy()->addHours($this->rand(1, 48))->min($this->now), true, null);
                            }
                        } else {
                            yield $this->row($review, $wrote, true, null);
                        }
                    }
                })());
            });
    }

    private function row(object $review, Carbon $wrote, bool $active, ?Carbon $deleted): array
    {
        return [
            'review_id' => $review->id, 'provider_id' => $review->provider_id, 'text' => $this->pick(self::REPLIES),
            'is_active' => $active ? 1 : null, 'deleted_at' => $deleted ? $this->ts($deleted) : null, 'deleted_by_user_id' => $deleted ? $review->provider_user : null,
            'created_at' => $this->ts($wrote), 'updated_at' => $this->ts($deleted ?? $wrote),
        ];
    }
}
