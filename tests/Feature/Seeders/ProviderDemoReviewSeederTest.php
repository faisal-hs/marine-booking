<?php

namespace Tests\Feature\Seeders;

use App\Models\Review;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProviderDemoReviewSeeder;
use Database\Seeders\ProviderMonthlyReservationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The demo reviews: something for a provider to look at and answer, made from completed bookings, never twice and never for a trip that has not happened. */
class ProviderDemoReviewSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->seed(ProviderMonthlyReservationSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function reviews(): array
    {
        return DB::table('reviews')->orderBy('uuid')->get(['uuid', 'customer_id', 'activity_id', 'rating', 'comment', 'created_at'])->map(fn ($r) => (array) $r)->all();
    }

    public function test_verified_providers_with_completed_bookings_get_reviews_up_to_eight_each(): void
    {
        $this->seed(ProviderDemoReviewSeeder::class);

        $perProvider = DB::table('reviews as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->selectRaw('a.provider_id, COUNT(*) AS n')->groupBy('a.provider_id')->pluck('n', 'provider_id');

        $this->assertGreaterThan(5, $perProvider->count());
        $this->assertSame(0, $perProvider->filter(fn ($n) => $n > 8)->count());
        $this->assertSame(0, DB::table('reviews as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->join('providers as p', 'p.id', '=', 'a.provider_id')->where('p.verification_status', '!=', 'verified')->count());
    }

    public function test_every_review_is_of_a_completed_booking_by_that_customer_after_the_trip(): void
    {
        $this->seed(ProviderDemoReviewSeeder::class);

        $bad = DB::table('reviews as r')->leftJoin('reservations as b', 'b.id', '=', 'r.reservation_id')
            ->where(fn ($q) => $q->whereNull('b.id')->orWhere('b.status', '!=', 'completed')->orWhereColumn('b.customer_id', '!=', 'r.customer_id')->orWhereColumn('b.activity_id', '!=', 'r.activity_id')
                ->orWhereRaw('DATE(r.created_at) <= b.reservation_date'))->count();

        $this->assertSame(0, $bad);
        $this->assertSame(0, DB::table('reviews')->where('created_at', '>', now()->toDateTimeString())->count());
    }

    public function test_a_customer_reviews_an_activity_once_and_ratings_are_valid(): void
    {
        $this->seed(ProviderDemoReviewSeeder::class);

        $this->assertSame(0, DB::table('reviews')->selectRaw('customer_id, activity_id, COUNT(*) AS n')->groupBy('customer_id', 'activity_id')->having('n', '>', 1)->get()->count());
        $this->assertSame(0, DB::table('reviews')->whereNotBetween('rating', [1, 5])->count());
        $this->assertGreaterThan(1, DB::table('reviews')->distinct()->count('rating'), 'Not all alike');
    }

    public function test_each_reviewed_activity_has_its_average_and_count_brought_in_line(): void
    {
        $this->seed(ProviderDemoReviewSeeder::class);

        foreach (DB::table('reviews')->groupBy('activity_id')->selectRaw('activity_id, AVG(rating) AS average, COUNT(*) AS total')->get() as $stats) {
            $activity = DB::table('activities')->find($stats->activity_id);
            $this->assertEquals(round($stats->average, 2), $activity->average_rating);
            $this->assertSame((int) $stats->total, (int) $activity->reviews_count);
        }
    }

    public function test_running_it_again_adds_nothing_and_it_is_the_same_every_time(): void
    {
        $this->seed(ProviderDemoReviewSeeder::class);
        $first = $this->reviews();

        $this->seed(ProviderDemoReviewSeeder::class);
        $this->assertSame($first, $this->reviews());

        DB::table('reviews')->delete();
        $this->seed(ProviderDemoReviewSeeder::class);
        $this->assertSame($first, $this->reviews(), 'The same reviews, ratings and words, from scratch');
    }

    public function test_it_leaves_existing_reviews_alone_and_adds_only_what_is_missing(): void
    {
        $this->seed(ProviderDemoReviewSeeder::class);
        $one = Review::orderBy('id')->first();
        $one->update(['rating' => 2, 'comment' => 'Edited by the customer']);
        $count = Review::count();

        $this->seed(ProviderDemoReviewSeeder::class);

        $this->assertSame($count, Review::count());
        $this->assertSame([2, 'Edited by the customer'], [$one->refresh()->rating, $one->comment]);
    }

    public function test_with_no_completed_bookings_it_makes_nothing_and_does_not_fail(): void
    {
        DB::table('reservations')->update(['status' => 'confirmed']);

        $this->seed(ProviderDemoReviewSeeder::class);

        $this->assertSame(0, Review::count());
    }
}
