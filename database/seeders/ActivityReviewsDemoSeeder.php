<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Bulk\BulkSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Reviews for EVERY activity that is or has been live (published, paused or ready) of a verified provider, so every activity page has a rating, a list of
 * reviews and replies to look at, and the provider's reviews screen has something to answer, filter and hide. Run it on top of the demo data:
 *
 *     php artisan db:seed --class=ActivityReviewsDemoSeeder
 *
 * For each such activity:
 *
 *  - SIX TO SIXTEEN REVIEWS (fewer if there are not that many customers), each by a different customer (a customer reviews an activity once). Each activity
 *    has its own quality, from poor through fair to very good, which shapes its ratings, so the averages differ from one activity to the next. About one in
 *    ten reviews is a rating alone, with no comment; the comments are in English and in Arabic.
 *  - THE BOOKING BEHIND IT where there is one: a customer who has a completed booking of the activity reviews that booking (the day after the trip at the
 *    earliest). A customer who has none still reviews it (a review does not have to be tied to a booking).
 *  - A FEW HIDDEN REVIEWS (0 to 8 in every hundred): hidden by an admin with a reason, so the public list leaves them out and the provider sees them marked.
 *  - REPLIES from the provider to about four to nine in ten of the visible reviews that are at least a day old.
 *  - The activity's AVERAGE RATING and REVIEW COUNT, brought in line with its visible reviews, as the app keeps them.
 *
 * Reviews are dated in the last 62 to 93 days (from 62 days before the 1st of the month, as the analytics demo data is) and never in the future; an activity
 * newer than that is given an earlier creation date, so nothing is reviewed before it existed. Every figure comes from the seeder's own random engine, started
 * afresh for each activity and customer, so it is the same on every run; running it again adds nothing, and on a later day it adds only what the new day makes
 * due. Reviews other seeders made are left alone and their customers are not reviewed twice.
 *
 * Volume at scale 1 (the demo data): about 65 activities of verified providers with an active account, so about 700 reviews (25 or so hidden) and 450 replies.
 * Prerequisites: DatabaseSeeder (providers, customers, activities). It does not need bookings, but uses completed ones where they exist (run
 * ProviderMonthlyReservationSeeder or ProviderAnalyticsDemoSeeder first for reviews tied to bookings).
 */
class ActivityReviewsDemoSeeder extends BulkSeeder
{
    private const DAYS_BEFORE_MONTH = 62;

    private const COMMENTS = [
        'Amazing experience, the crew was very professional.', 'A lovely day on the water. Would book again.', 'Great value and very well organised.',
        'The reef was beautiful and the guides were friendly.', 'Good trip, a little crowded but worth it.', 'Safe, clean and exactly as described.', 'The kids loved it.',
        'Everything started on time and the equipment was in great shape.', 'Our guide knew the area very well and was patient with beginners.',
        'Not what I expected, the boat was late.', 'A bit pricey for what it was.', 'The briefing was rushed, but the trip itself was good.',
        'تجربة رائعة، الطاقم محترف جداً.', 'رحلة جميلة وننصح بها بشدة.', 'قيمة ممتازة وتنظيم رائع.', 'الأطفال استمتعوا كثيراً.', 'الرحلة كانت جيدة لكن المكان مزدحم قليلاً.',
        'تأخر القارب عن الموعد وكانت التجربة أقل من المتوقع.', null,
    ];

    private const REPLIES = [
        'Thank you for your kind words, we hope to see you again soon!', 'We are glad you enjoyed it. See you next time.', 'Thanks for the feedback, we are working on it.',
        'We are sorry it was not perfect, and we have passed your comments to the crew.', 'شكراً لك على كلماتك الطيبة، نتطلع لاستقبالك مرة أخرى!', 'يسعدنا أنك استمتعت بالتجربة.',
        'نعتذر عن ذلك وسنأخذ ملاحظاتك بعين الاعتبار.',
    ];

    private const HIDDEN_REASONS = ['Contains offensive language.', 'Not about this activity.', 'Contains personal contact details.', 'Duplicate of another review.'];

    private int $reviews = 0;

    private int $replies = 0;

    private int $hidden = 0;

    protected function seed(): void
    {
        $this->needs('providers', 'ProviderSeeder', fn ($q) => $q->where('verification_status', 'verified'));
        $this->needs('customers', 'CustomerSeeder');
        $this->needs('activities', 'ActivitySeeder');

        $first = $this->now->copy()->startOfMonth()->subDays(self::DAYS_BEFORE_MONTH);
        $customers = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->whereNull('users.deleted_at')->where('users.status', 'active')
            ->orderBy('customers.id')->get(['customers.id', 'customers.user_id'])->all();
        $activities = DB::table('activities as a')->join('providers as p', 'p.id', '=', 'a.provider_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('a.status', ['published', 'inactive', 'approved'])->whereNull('a.deleted_at')->where('p.verification_status', 'verified')->whereNull('p.deleted_at')
            ->where('u.status', 'active')->whereNull('u.deleted_at')->orderBy('a.id')->get(['a.id', 'a.uuid', 'a.provider_id', 'p.user_id as provider_user_id', 'a.created_at'])->all();

        $touched = [];

        foreach ($activities as $activity) {
            $this->predate($activity, $first);

            if ($this->reviewActivity($activity, $customers, $first)) {
                $touched[] = $activity->id;
            }
        }

        $this->refreshRatings($touched);

        $this->line(sprintf('%d activities: %d reviews (%d hidden), %d replies.', count($activities), $this->reviews, $this->hidden, $this->replies));
    }

    /** An activity that is newer than the data is made older than it, so nothing is reviewed before it existed. */
    private function predate(object $activity, Carbon $first): void
    {
        $floor = $first->copy()->subDay()->setTime(9, 0);

        if (Carbon::parse($activity->created_at)->greaterThan($floor)) {
            DB::table('activities')->where('id', $activity->id)->update(['created_at' => $this->ts($floor)]);
            DB::table('activities')->where('id', $activity->id)->whereNotNull('published_at')->update(['published_at' => $this->ts($floor->copy()->addHour())]);
        }
    }

    /** @param  list<object>  $customers */
    private function reviewActivity(object $activity, array $customers, Carbon $first): bool
    {
        $roll = fn (string $what, int $min, int $max) => $min + (crc32("{$what}|{$activity->uuid}|".config('seeding.seed')) % ($max - $min + 1));
        $quality = $roll('quality', 0, 2);   // 0 poor, 1 fair, 2 very good
        $target = $roll('count', 6, 16);
        $hiddenShare = $roll('hidden', 0, 8);
        $answers = $roll('answers', 40, 90);

        // Who has already reviewed this activity (by any seeder, or by hand), and which of those reviews are this seeder's own.
        $existing = DB::table('reviews')->where('activity_id', $activity->id)->pluck('uuid', 'customer_id')->all();

        // The customers in an order of this activity's own, from which the first $target that are free to review it are taken.
        $this->startRandom((crc32("reviews-demo|{$activity->id}") ^ (int) config('seeding.seed')) & 0x7FFFFFFF);
        $order = $customers;
        for ($i = count($order) - 1; $i > 0; $i--) {
            $j = $this->rand(0, $i);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }

        $made = [];
        $own = [];
        $chosen = 0;

        foreach ($order as $customer) {
            if ($chosen >= $target) {
                break;
            }

            $uuid = $this->uuid('reviews-demo', "{$activity->id}-{$customer->id}");

            // Someone else's review of it (another seeder's, or a real one): that customer is taken, and does not count towards this seeder's.
            if ($customer->user_id === $activity->provider_user_id || (isset($existing[$customer->id]) && $existing[$customer->id] !== $uuid)) {
                continue;
            }

            $chosen++;
            $own[] = $uuid;
            $row = $this->review($activity, $customer, $uuid, $first, $quality, $hiddenShare);

            if ($row !== null && ! isset($existing[$customer->id])) {
                $made[] = $row;
            }
        }

        $this->reviews += $this->insert('reviews', $made);
        $this->hidden += count(array_filter($made, fn ($r) => $r['is_hidden']));

        // Replies are considered for ALL of this seeder's reviews of the activity, not just the new ones: one that has become a day old since the last run is due.
        $this->reply($activity, $own, $answers);

        return $made !== [];
    }

    /** @return array<string, mixed>|null the review, or null when it is not due yet */
    private function review(object $activity, object $customer, string $uuid, Carbon $first, int $quality, int $hiddenShare): ?array
    {
        // Each review draws from a stream of its own, so it comes out the same whenever it is made.
        $this->startRandom((crc32("reviews-demo-draw|{$uuid}") ^ (int) config('seeding.seed')) & 0x7FFFFFFF);

        $span = max(1, (int) $first->diffInDays($this->now->copy()->startOfDay()));
        $at = $first->copy()->addDays($this->rand(0, $span - 1))->setTime(8, 0)->addMinutes($this->rand(0, 840));

        // On the customer's completed booking of the activity, when there is one: the day after the trip at the earliest.
        $trip = DB::table('reservations')->where('customer_id', $customer->id)->where('activity_id', $activity->id)->where('status', 'completed')->whereNull('deleted_at')
            ->orderByDesc('reservation_date')->first(['id', 'reservation_date']);
        $reservationId = null;
        if ($trip) {
            $earliest = Carbon::parse($trip->reservation_date)->addDay()->setTime(10, 0);
            if ($earliest->lessThanOrEqualTo($this->now)) {
                $reservationId = $trip->id;
                $at = $at->lessThan($earliest) ? $earliest->copy()->addMinutes($this->rand(0, 600)) : $at;
            }
        }

        if ($at->greaterThanOrEqualTo($this->now)) {
            return null;
        }

        $hidden = $this->chance($hiddenShare);

        return [
            'uuid' => $uuid, 'customer_id' => $customer->id, 'activity_id' => $activity->id, 'reservation_id' => $reservationId,
            'rating' => (int) $this->weighted([
                0 => ['5' => 22, '4' => 18, '3' => 22, '2' => 22, '1' => 16],
                1 => ['5' => 42, '4' => 30, '3' => 16, '2' => 8, '1' => 4],
                2 => ['5' => 66, '4' => 24, '3' => 7, '2' => 2, '1' => 1],
            ][$quality]),
            'comment' => $this->pick(self::COMMENTS), 'is_hidden' => $hidden ? 1 : 0, 'hidden_reason' => $hidden ? $this->pick(self::HIDDEN_REASONS) : null,
            'hidden_at' => $hidden ? $this->ts(min($at->copy()->addHours($this->rand(2, 72)), $this->now->copy())) : null,
            'created_at' => $this->ts($at), 'updated_at' => $this->ts($at),
        ];
    }

    /** Replies to some of the visible reviews that are at least a day old. */
    private function reply(object $activity, array $uuids, int $answers): void
    {
        $rows = [];

        foreach (DB::table('reviews')->whereIn('uuid', $uuids)->where('is_hidden', 0)->orderBy('id')->get(['id', 'uuid', 'created_at']) as $review) {
            $at = Carbon::parse($review->created_at)->addMinutes((1 + crc32("hours|{$review->uuid}") % 36) * 60);

            if ($at->lessThanOrEqualTo($this->now) && $this->stable("reviews-demo-reply|{$review->uuid}", $answers)) {
                $rows[] = ['review_id' => $review->id, 'provider_id' => $activity->provider_id, 'text' => self::REPLIES[crc32($review->uuid) % count(self::REPLIES)],
                    'is_active' => 1, 'deleted_at' => null, 'deleted_by_user_id' => null, 'created_at' => $this->ts($at), 'updated_at' => $this->ts($at)];
            }
        }

        $this->replies += $this->insert('review_replies', $rows);
    }

    /** The average and the count of every reviewed activity, from its visible reviews, exactly as the app keeps them. */
    private function refreshRatings(array $activityIds): void
    {
        if ($activityIds === []) {
            return;
        }

        foreach (DB::table('reviews')->where('is_hidden', 0)->whereIn('activity_id', $activityIds)->groupBy('activity_id')->selectRaw('activity_id, AVG(rating) AS average, COUNT(*) AS total')->get() as $stats) {
            DB::table('activities')->where('id', $stats->activity_id)->update(['average_rating' => round((float) $stats->average, 2), 'reviews_count' => $stats->total]);
        }
    }
}
