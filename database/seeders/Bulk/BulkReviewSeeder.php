<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;

/**
 * Reviews, only where the app would allow one: the customer COMPLETED a booking of that activity, and writes at most
 * one review per activity. 45% of eligible bookings were reviewed; ratings lean positive (50% five stars, 30% four,
 * 12% three, 5% two, 3% one); 60% have a comment; 3% were hidden by a moderator.
 * It then sets every reviewed activity's average rating and review count from the VISIBLE reviews, as the app does.
 *
 * Volume at scale 1: about 26,000.
 * Prerequisites: BulkReservationSeeder.
 */
class BulkReviewSeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $this->needs('reservations', 'BulkReservationSeeder', fn ($q) => $q->where('status', 'completed'));

        $seen = [];
        foreach (DB::table('reviews')->get(['customer_id', 'activity_id']) as $existing) {
            $seen[$existing->customer_id.'|'.$existing->activity_id] = true;
        }

        $comments = $this->reviewComments();

        $this->insert('reviews', (function () use (&$seen, $comments) {
            $last = 0;
            while (true) {
                $reservations = DB::table('reservations')->where('status', 'completed')->where('id', '>', $last)
                    ->orderBy('id')->limit(2000)->get(['id', 'customer_id', 'activity_id', 'reservation_date']);

                if ($reservations->isEmpty()) {
                    return;
                }

                foreach ($reservations as $reservation) {
                    $pair = $reservation->customer_id.'|'.$reservation->activity_id;
                    if (isset($seen[$pair]) || ! $this->stable("review-{$reservation->id}", 45)) {
                        continue;
                    }
                    $seen[$pair] = true;

                    $rating = (int) $this->weighted(['5' => 50, '4' => 30, '3' => 12, '2' => 5, '1' => 3]);
                    $written = Carbon::parse($reservation->reservation_date)->addDays($this->rand(0, 7))->setTime($this->rand(8, 22), $this->rand(0, 59));
                    $written = $written->greaterThan($this->now) ? $this->now->copy() : $written;
                    $hidden = $this->chance(3);

                    yield [
                        'uuid' => $this->uuid('review', $reservation->id),
                        'customer_id' => $reservation->customer_id,
                        'activity_id' => $reservation->activity_id,
                        'reservation_id' => $reservation->id,
                        'rating' => $rating,
                        'comment' => $this->chance(60) ? $this->pick($comments[$rating]) : null,
                        'is_hidden' => $hidden ? 1 : 0,
                        'hidden_reason' => $hidden ? $this->pick(['Inappropriate language', 'Spam', 'Contains personal data', 'Not about this activity']) : null,
                        'hidden_at' => $hidden ? $this->ts($written->copy()->addDays($this->rand(1, 20))->min($this->now)) : null,
                        'created_at' => $this->ts($written),
                        'updated_at' => $this->ts($written),
                    ];
                }

                $last = $reservations->last()->id;
            }
        })());

        $this->refreshRatings();
    }

    /** Average and count over the visible reviews, per activity, exactly as ReviewService keeps them. */
    private function refreshRatings(): void
    {
        $stats = DB::table('reviews')->where('is_hidden', 0)->groupBy('activity_id')
            ->selectRaw('activity_id, AVG(rating) as average, COUNT(*) as total')->get();

        foreach ($stats as $row) {
            DB::table('activities')->where('id', $row->activity_id)->update(['average_rating' => round((float) $row->average, 2), 'reviews_count' => (int) $row->total]);
        }
    }
}
