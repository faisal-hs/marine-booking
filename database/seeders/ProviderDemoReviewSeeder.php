<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Bulk\BulkSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Reviews for the demo providers, so there is something to look at and reply to: for each verified provider, up to eight of their completed bookings
 * (one per customer and activity: a customer reviews an activity once) are reviewed the day after the trip. Ratings lean towards the top, a few are
 * low, and each review's activity gets its average rating and review count brought in line, as the app does. Run it AFTER the demo data and
 * ProviderMonthlyReservationSeeder (which creates the completed bookings); it adds only what is missing, so running it again changes nothing.
 * Volume at scale 1: up to eight reviews per verified provider (about a hundred and twenty with the demo data).
 * Prerequisites: ProviderMonthlyReservationSeeder (or any completed bookings).
 */
class ProviderDemoReviewSeeder extends BulkSeeder
{
    private const COMMENTS = ['Amazing experience, the crew was very professional.', 'A lovely day on the water. Would book again.', 'Great value and very well organised.',
        'The reef was beautiful and the guides were friendly.', 'Good trip, a little crowded but worth it.', 'Not what I expected, the boat was late.', null];

    protected function seed(): void
    {
        $taken = [];
        foreach (DB::table('reviews')->get(['customer_id', 'activity_id']) as $review) {
            $taken["{$review->customer_id}-{$review->activity_id}"] = true;
        }

        $rows = [];
        $touched = [];

        foreach (DB::table('providers')->where('verification_status', 'verified')->orderBy('id')->pluck('id') as $providerId) {
            $bookings = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $providerId)->where('r.status', 'completed')->whereNull('r.deleted_at')
                ->orderBy('r.id')->get(['r.id', 'r.customer_id', 'r.activity_id', 'r.reservation_date']);
            // Up to eight reviews IN ALL: the ones the provider already has count, so running it again adds nothing.
            $made = DB::table('reviews as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $providerId)->count();

            foreach ($bookings as $booking) {
                $key = "{$booking->customer_id}-{$booking->activity_id}";
                $at = Carbon::parse($booking->reservation_date)->addDay()->setTime(10, 0);

                if ($made >= 8 || isset($taken[$key]) || $at->greaterThan($this->now)) {
                    continue;
                }

                $taken[$key] = true;
                $touched[$booking->activity_id] = true;
                $made++;
                $rows[] = [
                    'uuid' => $this->uuid('demo-review', $booking->id), 'customer_id' => $booking->customer_id, 'activity_id' => $booking->activity_id, 'reservation_id' => $booking->id,
                    'rating' => (int) $this->weighted(['5' => 55, '4' => 25, '3' => 10, '2' => 6, '1' => 4]), 'comment' => $this->pick(self::COMMENTS),
                    'is_hidden' => 0, 'hidden_reason' => null, 'hidden_at' => null, 'created_at' => $this->ts($at), 'updated_at' => $this->ts($at),
                ];
            }
        }

        $this->insert('reviews', $rows);

        // The average and the count of every reviewed activity, from its visible reviews, exactly as the app keeps them.
        foreach (DB::table('reviews')->where('is_hidden', 0)->whereIn('activity_id', array_keys($touched))->groupBy('activity_id')->selectRaw('activity_id, AVG(rating) AS average, COUNT(*) AS total')->get() as $stats) {
            DB::table('activities')->where('id', $stats->activity_id)->update(['average_rating' => round((float) $stats->average, 2), 'reviews_count' => $stats->total]);
        }
    }
}
