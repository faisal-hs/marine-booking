<?php

namespace Tests\Feature\Seeders;

use App\Models\Customer;
use Carbon\Carbon;
use Database\Seeders\ActivityReviewsDemoSeeder;
use Database\Seeders\ProviderAnalyticsDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\Concerns\UsesSeededDemoDatabase;
use Tests\TestCase;

/**
 * ActivityReviewsDemoSeeder gives every activity that is or has been live reviews, hidden reviews and replies. The data is built once for the class (the demo data,
 * then the analytics demo data, which makes bookings and reviews of its own, then this seeder), so the tests also show that the two seeders live together.
 */
class ActivityReviewsDemoSeederTest extends TestCase
{
    use BuildsProviderScenarios;
    use UsesSeededDemoDatabase;

    /** The window the reviews are dated in: 62 days before the 1st of the month the data was seeded in. */
    private function first(): Carbon
    {
        return $this->demoSeededAt()->startOfMonth()->subDays(62);
    }

    protected function seedDemo(): void
    {
        // A provider whose account is ALSO a customer (an account can switch): the demo data has none, and it is the case in which "nobody reviews their own activity" matters.
        $owner = DB::table('providers as p')->join('users as u', 'u.id', '=', 'p.user_id')->where('p.verification_status', 'verified')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('activities as a')->whereColumn('a.provider_id', 'p.id')->where('a.status', 'published'))->orderBy('p.id')->value('p.user_id');
        Customer::create(['user_id' => $owner]);

        $this->seed(ProviderAnalyticsDemoSeeder::class);
        $this->seed(ActivityReviewsDemoSeeder::class);
    }

    /** @return list<int> the activities that are or have been live, of verified providers with an active account */
    private function reviewable(): array
    {
        return DB::table('activities as a')->join('providers as p', 'p.id', '=', 'a.provider_id')->join('users as u', 'u.id', '=', 'p.user_id')->whereIn('a.status', ['published', 'inactive', 'approved'])
            ->whereNull('a.deleted_at')->where('p.verification_status', 'verified')->whereNull('p.deleted_at')->where('u.status', 'active')->whereNull('u.deleted_at')->orderBy('a.id')->pluck('a.id')->map(fn ($i) => (int) $i)->all();
    }

    private function ours()
    {
        return DB::table('reviews')->where('uuid', '!=', '');
    }

    private function counts(): array
    {
        return [DB::table('reviews')->count(), DB::table('review_replies')->count(), DB::table('activities')->sum('reviews_count')];
    }

    // --- Who gets reviews -----------------------------------------------------------------------------------------------

    public function test_every_activity_that_is_or_has_been_live_has_reviews_and_no_other_does_from_this_seeder(): void
    {
        $reviewable = $this->reviewable();
        $this->assertGreaterThan(30, count($reviewable));

        foreach ($reviewable as $id) {
            $this->assertGreaterThanOrEqual(6, DB::table('reviews')->where('activity_id', $id)->count(), "Activity {$id}");
        }

        $others = DB::table('reviews')->whereNotIn('activity_id', $reviewable)->count();
        $this->assertSame(0, $others, 'Drafts, rejected activities and those of providers who are not verified have no reviews');
    }

    public function test_the_number_of_reviews_of_an_activity_varies_and_is_at_most_sixteen_from_this_seeder(): void
    {
        $perActivity = DB::table('reviews')->selectRaw('activity_id, COUNT(*) AS n')->groupBy('activity_id')->pluck('n');

        $this->assertGreaterThan(3, $perActivity->unique()->count(), 'Not the same number everywhere');
        $this->assertGreaterThanOrEqual(10, $perActivity->max());
    }

    public function test_a_customer_reviews_an_activity_once_and_never_their_own(): void
    {
        $this->assertSame(1, DB::table('customers')->join('providers', 'providers.user_id', '=', 'customers.user_id')->count(), 'There is a provider who is also a customer to test it on');
        $this->assertSame(0, DB::table('reviews')->selectRaw('COUNT(*) AS n')->groupBy('customer_id', 'activity_id')->having('n', '>', 1)->get()->count());
        $this->assertSame(0, DB::table('reviews as v')->join('activities as a', 'a.id', '=', 'v.activity_id')->join('providers as p', 'p.id', '=', 'a.provider_id')->join('customers as c', 'c.id', '=', 'v.customer_id')
            ->whereColumn('c.user_id', 'p.user_id')->count(), 'Nobody reviews their own activity');
    }

    // --- What they say --------------------------------------------------------------------------------------------------------

    public function test_ratings_cover_all_five_stars_and_activities_differ_in_how_good_they_are(): void
    {
        $this->assertSame([1, 5], [(int) DB::table('reviews')->min('rating'), (int) DB::table('reviews')->max('rating')]);
        $averages = DB::table('reviews')->where('is_hidden', 0)->selectRaw('activity_id, AVG(rating) AS a')->groupBy('activity_id')->pluck('a');

        $this->assertLessThan(3.6, $averages->min(), 'Some activities are poorly rated');
        $this->assertGreaterThan(4.2, $averages->max(), 'Some are very well rated');
        $this->assertGreaterThan(3.3, DB::table('reviews')->avg('rating'));
    }

    public function test_comments_come_in_english_and_arabic_and_some_reviews_are_a_rating_alone(): void
    {
        $comments = DB::table('reviews')->whereNotNull('comment')->pluck('comment');

        $this->assertTrue($comments->contains(fn ($c) => preg_match('/[\x{0600}-\x{06FF}]/u', $c) === 1), 'Arabic');
        $this->assertTrue($comments->contains(fn ($c) => preg_match('/^[A-Za-z]/', $c) === 1), 'English');
        $ratingOnly = DB::table('reviews')->whereNull('comment')->count() / max(1, DB::table('reviews')->count());
        $this->assertGreaterThan(0.01, $ratingOnly);
        $this->assertLessThan(0.30, $ratingOnly);
    }

    // --- The booking behind a review --------------------------------------------------------------------------------------

    public function test_a_review_tied_to_a_booking_is_on_that_customers_completed_booking_of_that_activity_and_after_the_trip(): void
    {
        $tied = DB::table('reviews as v')->join('reservations as r', 'r.id', '=', 'v.reservation_id')->whereNotNull('v.reservation_id');
        $this->assertGreaterThan(0, (clone $tied)->count(), 'Some reviews are tied to a booking');
        $this->assertSame(0, (clone $tied)->where(fn ($q) => $q->where('r.status', '!=', 'completed')->orWhereColumn('r.activity_id', '!=', 'v.activity_id')->orWhereColumn('r.customer_id', '!=', 'v.customer_id'))->count());
        $this->assertSame(0, (clone $tied)->whereRaw("v.created_at < datetime(r.reservation_date, '+1 day')")->count(), 'Never before the day after the trip');
        $this->assertGreaterThan(0, DB::table('reviews')->whereNull('reservation_id')->count(), 'And some are not tied to one');
    }

    public function test_a_customer_with_a_completed_booking_of_the_activity_reviews_it_on_that_booking_when_the_review_was_made_by_this_seeder(): void
    {
        // Where this seeder made the review, and the customer has a completed booking of the activity, the review is tied to it.
        $loose = DB::table('reviews as v')->whereNull('v.reservation_id')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('reservations as r')->whereColumn('r.customer_id', 'v.customer_id')
            ->whereColumn('r.activity_id', 'v.activity_id')->where('r.status', 'completed')->whereRaw("datetime(r.reservation_date, '+1 day', '+10 hours') <= v.created_at"))->count();

        $this->assertSame(0, $loose, 'A completed booking was available and not used');
    }

    /**
     * The booking a review is tied to is the latest one that can be reviewed BY NOW (the day after the trip, 10:00), not simply the latest. A customer who went on the activity
     * five days ago and again today is reviewed on the first trip, whatever the time of day: today's trip cannot be reviewed until tomorrow.
     */
    public function test_a_trip_that_cannot_be_reviewed_yet_does_not_stop_an_older_one_from_being_used(): void
    {
        $base = DB::table('reservations')->where('status', 'completed')->orderBy('id')->first();
        $activity = DB::table('activities')->where('id', $base->activity_id)->first();
        $customer = DB::table('customers')->where('id', $base->customer_id)->first();
        $clone = fn (string $uuid, string $date) => array_merge((array) $base, ['id' => null, 'uuid' => $uuid, 'idempotency_key' => 'test-'.$uuid, 'reservation_date' => $date, 'status' => 'completed']);

        DB::beginTransaction();
        try {
            DB::table('reviews')->where('customer_id', $customer->id)->where('activity_id', $activity->id)->delete();
            DB::table('reservations')->where('customer_id', $customer->id)->where('activity_id', $activity->id)->update(['deleted_at' => now()]);
            $old = DB::table('reservations')->insertGetId(array_diff_key($clone((string) Str::uuid(), $this->demoSeededAt()->subDays(5)->toDateString()), ['id' => 1]));
            $today = DB::table('reservations')->insertGetId(array_diff_key($clone((string) Str::uuid(), $this->demoSeededAt()->toDateString()), ['id' => 1]));

            $seeder = app(ActivityReviewsDemoSeeder::class);
            $set = function (string $name, $value) use ($seeder) {
                $property = new \ReflectionProperty(ActivityReviewsDemoSeeder::class, $name);
                $property->setAccessible(true);
                $property->setValue($seeder, $value);
            };
            $set('now', $this->demoSeededAt()->addMinutes(5));
            $method = new \ReflectionMethod($seeder, 'review');
            $method->setAccessible(true);
            $row = $method->invoke($seeder, (object) ['id' => $activity->id, 'uuid' => $activity->uuid], (object) ['id' => $customer->id, 'user_id' => $customer->user_id], (string) Str::uuid(), $this->first(), 2, 0);
        } finally {
            DB::rollBack();
        }

        $this->assertNotNull($row, 'The review is made');
        $this->assertSame($old, $row['reservation_id'], 'It is tied to the trip of five days ago, not to today\'s (which cannot be reviewed yet) and not to nothing');
        $this->assertNotSame($today, $row['reservation_id']);
        $this->assertGreaterThanOrEqual($this->demoSeededAt()->subDays(4)->setTime(10, 0)->toDateTimeString(), $row['created_at'], 'Never before the day after that trip');
    }

    // --- Hidden reviews ---------------------------------------------------------------------------------------------------

    public function test_some_reviews_are_hidden_by_an_admin_with_a_reason_and_a_time_and_the_others_are_not(): void
    {
        $this->assertGreaterThan(5, DB::table('reviews')->where('is_hidden', 1)->count());
        $this->assertSame(0, DB::table('reviews')->where('is_hidden', 1)->where(fn ($q) => $q->whereNull('hidden_reason')->orWhereNull('hidden_at')->orWhereColumn('hidden_at', '<', 'created_at'))->count());
        $this->assertSame(0, DB::table('reviews')->where('is_hidden', 0)->where(fn ($q) => $q->whereNotNull('hidden_reason')->orWhereNotNull('hidden_at'))->count());
        $this->assertSame(0, DB::table('reviews')->where('hidden_at', '>', $this->demoSeededAt()->addHour()->toDateTimeString())->count(), 'Not in the future');
    }

    // --- Replies -----------------------------------------------------------------------------------------------------------

    public function test_replies_are_from_the_activitys_provider_after_the_review_one_each_and_never_on_a_hidden_review(): void
    {
        $this->assertGreaterThan(100, DB::table('review_replies')->count());
        $this->assertSame(0, DB::table('review_replies as p')->join('reviews as v', 'v.id', '=', 'p.review_id')->join('activities as a', 'a.id', '=', 'v.activity_id')
            ->where(fn ($q) => $q->whereColumn('p.provider_id', '!=', 'a.provider_id')->orWhereColumn('p.created_at', '<', 'v.created_at')->orWhere('v.is_hidden', 1))->count());
        $this->assertSame(0, DB::table('review_replies')->selectRaw('COUNT(*) AS n')->where('is_active', 1)->groupBy('review_id')->having('n', '>', 1)->get()->count());
    }

    public function test_the_providers_answer_a_share_of_their_reviews_that_is_neither_none_nor_all(): void
    {
        $visible = DB::table('reviews')->where('is_hidden', 0)->whereNotNull('uuid')->where('created_at', '<', $this->demoSeededAt()->subDay()->toDateTimeString())->count();
        $answered = DB::table('review_replies')->count();

        $this->assertGreaterThan(0.35, $answered / $visible);
        $this->assertLessThan(0.97, $answered / $visible);
    }

    // --- The average and the count on the activity ------------------------------------------------------------------------

    public function test_every_activitys_average_and_count_are_those_of_its_visible_reviews(): void
    {
        foreach ($this->reviewable() as $id) {
            $stats = DB::table('reviews')->where('activity_id', $id)->where('is_hidden', 0)->selectRaw('COUNT(*) AS n, AVG(rating) AS a')->first();
            $activity = DB::table('activities')->where('id', $id)->first(['average_rating', 'reviews_count']);

            $this->assertEquals($stats->n, $activity->reviews_count, "Count of {$id}");
            $this->assertEqualsWithDelta((float) $stats->a, (float) $activity->average_rating, 0.01, "Average of {$id}");
        }
    }

    // --- Dates -----------------------------------------------------------------------------------------------------------

    public function test_reviews_are_dated_in_the_window_after_their_activity_existed_and_never_in_the_future(): void
    {
        $this->assertSame(0, DB::table('reviews')->where('created_at', '>', $this->demoSeededAt()->addHour()->toDateTimeString())->count(), 'Not in the future');
        $this->assertSame(0, DB::table('reviews as v')->join('activities as a', 'a.id', '=', 'v.activity_id')->whereColumn('v.created_at', '<', 'a.created_at')->count(), 'After the activity existed');
        $this->assertSame(0, DB::table('review_replies')->where('created_at', '>', $this->demoSeededAt()->addHour()->toDateTimeString())->count());
        $this->assertSame(0, DB::table('activities')->whereIn('id', $this->reviewable())->whereNotNull('published_at')->whereColumn('published_at', '<', 'created_at')->count());
    }

    // --- What the app shows ------------------------------------------------------------------------------------------------

    public function test_the_customer_app_shows_the_visible_reviews_and_not_the_hidden_ones(): void
    {
        $id = (int) DB::table('reviews')->where('is_hidden', 1)->value('activity_id');
        $visible = DB::table('reviews')->where('activity_id', $id)->where('is_hidden', 0)->count();

        $r = $this->getJson("/api/v1/customer-app/activities/{$id}/reviews?per_page=50");

        // The page lists what a customer can see: the visible reviews, none of the hidden ones (the activity may be paused, in which case it is not shown at all).
        if ($r->status() === 200) {
            $this->assertSame($visible, $r->json('meta.total'));
            $this->assertGreaterThan(0, collect($r->json('data'))->whereNotNull('provider_reply')->count() + 1);
        } else {
            $this->assertSame(404, $r->status());
        }
    }

    // --- Running it again -----------------------------------------------------------------------------------------------

    public function test_running_it_again_adds_nothing(): void
    {
        $before = $this->counts();

        $this->seed(ActivityReviewsDemoSeeder::class);
        $this->seed(ActivityReviewsDemoSeeder::class);

        $this->assertSame($before, $this->counts());
    }

    public function test_it_makes_the_same_reviews_every_time_the_clock_being_the_same(): void
    {
        $snapshot = fn () => [DB::table('reviews')->orderBy('uuid')->get(['uuid', 'customer_id', 'activity_id', 'reservation_id', 'rating', 'comment', 'is_hidden', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            DB::table('review_replies')->join('reviews', 'reviews.id', '=', 'review_replies.review_id')->orderBy('reviews.uuid')->get(['reviews.uuid', 'review_replies.text', 'review_replies.created_at'])->map(fn ($r) => (array) $r)->all()];
        $remake = function () {
            DB::table('review_replies')->delete();
            DB::table('reviews')->delete();
            $this->seed(ProviderAnalyticsDemoSeeder::class);
            $this->seed(ActivityReviewsDemoSeeder::class);
        };

        // Its dates are placed relative to now, so "the same" means with the same clock: it is pinned for both makings. Everything is done inside a transaction
        // that is rolled back, so the shared data is left as it was.
        Carbon::setTestNow($this->demoSeededAt()->addMinutes(5));
        DB::beginTransaction();
        try {
            $remake();
            $first = $snapshot();
            $remake();
            $again = $snapshot();
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        $this->assertNotEmpty($first[0]);
        $this->assertSame($first, $again);
    }
}
