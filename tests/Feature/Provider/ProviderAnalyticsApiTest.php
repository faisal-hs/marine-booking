<?php

namespace Tests\Feature\ProviderApp;

use App\Models\ReviewReply;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/analytics. Today is Sunday 15 November 2026, so the default period is 17 October to 15 November (30 days) and the one before it is
 * 17 September to 16 October. Every figure is worked out the way ProviderAnalytics documents it; revenue comes from the wallet ledger.
 */
class ProviderAnalyticsApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function report(string $query = '', ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/analytics'.$query);
    }

    private function data(string $query = '', ?array $as = null): array
    {
        return $this->report($query, $as)->assertOk()->json('data');
    }

    /** $n visitors who looked at the activity on a day. */
    private function views(int $n, string $day, string $source = 'direct', ?array $s = null, string $tag = ''): void
    {
        $id = ($s ?? $this->a)['activity']->id;
        for ($i = 1; $i <= $n; $i++) {
            DB::table('activity_views')->insert(['activity_id' => $id, 'visitor_hash' => sha1("{$tag}{$id}-{$day}-{$source}-{$i}"), 'viewed_on' => $day, 'source' => $source, 'created_at' => now()]);
        }
    }

    /** A booking made on a day (its trip on another). */
    private function booking(string $status, string $madeOn, ?string $source = 'direct', ?array $s = null, string $trip = '2026-11-20', float $total = 1000): int
    {
        return $this->makeBooking($s ?? $this->a, $this->customerNamed('C'.uniqid()), $trip, $status, $total, ['source' => $source, 'created_at' => "{$madeOn} 10:00:00", 'updated_at' => "{$madeOn} 10:00:00"]);
    }

    private function second(string $title = 'Sunset Cruise'): array
    {
        [$activity] = $this->makeBookableActivity($this->a['provider'], 'published', ['title' => $title]);

        return array_merge($this->a, ['activity' => $activity, 'option' => $activity->bookingOptions()->first(), 'period' => $activity->schedulePeriods()->value('id')]);
    }

    // --- The shape and the period ---------------------------------------------------------------------------------------

    public function test_a_provider_with_nothing_yet_gets_zeros_and_nulls_not_an_error(): void
    {
        $d = $this->data();

        $this->assertSame(['period', 'page_views', 'page_views_change', 'conversion_rate', 'conversion_change', 'avg_rating', 'response_rate', 'top_activities', 'booking_sources'], array_keys($d));
        $this->assertSame([0, null, 0, null, null, null, []], [$d['page_views'], $d['page_views_change'], $d['conversion_rate'], $d['conversion_change'], $d['avg_rating'], $d['response_rate'], $d['top_activities']]);
        $this->assertSame([['source' => 'direct', 'percent' => 0], ['source' => 'category', 'percent' => 0], ['source' => 'recommendations', 'percent' => 0], ['source' => 'social', 'percent' => 0]], $d['booking_sources']);
    }

    public function test_the_default_period_is_the_last_thirty_days_and_a_chosen_one_is_used(): void
    {
        $this->assertSame(['from' => '2026-10-17', 'to' => '2026-11-15'], $this->data()['period']);
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-07'], $this->data('?from=2026-11-01&to=2026-11-07')['period']);
        $this->assertSame(['from' => '2026-10-12', 'to' => '2026-11-10'], $this->data('?to=2026-11-10')['period'], 'Thirty days ending on the chosen day');
    }

    // --- Page views -----------------------------------------------------------------------------------------------------

    public function test_page_views_are_those_in_the_period_boundaries_included(): void
    {
        $this->views(3, '2026-10-17');
        $this->views(2, '2026-11-15');
        $this->views(5, '2026-10-16');   // the day before: the previous period
        $this->views(4, '2026-11-16');   // tomorrow

        $this->assertSame(5, $this->data()['page_views']);
    }

    public function test_the_change_is_against_the_period_of_the_same_length_just_before(): void
    {
        $this->views(13, '2026-11-01');
        $this->views(10, '2026-10-01');

        $d = $this->data();

        $this->assertSame([13, 30], [$d['page_views'], $d['page_views_change']]);
    }

    public function test_the_previous_period_starts_and_ends_on_exactly_the_right_days(): void
    {
        // The period is 17 October to 15 November (30 days), so the one before it is 17 September to 16 October: 30 days, no gap, no overlap.
        $this->views(10, '2026-11-01');
        $this->views(4, '2026-09-17');   // its first day: counted
        $this->views(2, '2026-10-16');   // its last day: counted
        $this->views(5, '2026-09-16');   // the day before it: not counted
        $this->views(7, '2026-10-17');   // the first day of THIS period: not part of the previous one

        $d = $this->data();

        $this->assertSame(17, $d['page_views']);
        $this->assertSame(183.3, $d['page_views_change'], '17 now against 4 + 2 before: +183.3%');
    }

    public function test_a_fall_is_negative_and_no_earlier_views_means_no_change_to_state(): void
    {
        $this->views(5, '2026-11-01');
        $this->views(20, '2026-10-01');
        $this->assertSame(-75, $this->data()['page_views_change']);

        DB::table('activity_views')->where('viewed_on', '2026-10-01')->delete();
        $this->assertNull($this->data()['page_views_change']);
    }

    // --- Conversion ---------------------------------------------------------------------------------------------------

    public function test_conversion_is_the_bookings_made_in_the_period_over_the_page_views_in_it(): void
    {
        $this->views(20, '2026-11-01');
        $this->booking('confirmed', '2026-11-02');
        $this->booking('confirmed', '2026-10-20');
        $this->booking('completed', '2026-11-10');

        $this->assertSame(15, $this->data()['conversion_rate']);
    }

    public function test_a_cancelled_expired_or_unpaid_booking_is_not_a_booking(): void
    {
        $this->views(10, '2026-11-01');
        $this->booking('confirmed', '2026-11-02');
        foreach (['cancelled', 'expired', 'pending'] as $status) {
            $this->booking($status, '2026-11-03');
        }

        $this->assertSame(10, $this->data()['conversion_rate']);
    }

    public function test_a_booking_made_outside_the_period_does_not_count(): void
    {
        $this->views(10, '2026-11-01');
        $this->booking('confirmed', '2026-10-16');   // before
        $this->booking('confirmed', '2026-11-16');   // after

        $this->assertSame(0, $this->data()['conversion_rate']);
    }

    public function test_the_conversion_change_is_in_percentage_points(): void
    {
        $this->views(20, '2026-11-01');
        $this->booking('confirmed', '2026-11-02');
        $this->booking('confirmed', '2026-11-03');           // 2 of 20: 10
        $this->views(10, '2026-10-01');
        $this->booking('confirmed', '2026-10-02');           // 1 of 10: 10 before

        $this->assertSame(0, $this->data()['conversion_change']);

        $this->booking('confirmed', '2026-11-04');           // 3 of 20: 15
        $this->assertSame(5, $this->data()['conversion_change']);
    }

    public function test_conversion_is_zero_when_nobody_looked(): void
    {
        $this->booking('confirmed', '2026-11-02');

        $this->assertSame(0, $this->data()['conversion_rate']);
    }

    // --- Ratings ----------------------------------------------------------------------------------------------------

    public function test_the_average_rating_is_over_every_visible_review_not_just_the_period(): void
    {
        $this->makeReview($this->a['activity'], $this->customerNamed('A'), 5, 'x', ['created_at' => '2026-01-10 10:00:00']);
        $this->makeReview($this->a['activity'], $this->customerNamed('B'), 4, 'x', ['created_at' => '2026-11-10 10:00:00']);
        $this->makeReview($this->a['activity'], $this->customerNamed('C'), 4, 'x');
        $hidden = $this->makeReview($this->a['activity'], $this->customerNamed('D'), 1, 'Abusive');
        $hidden->update(['is_hidden' => true, 'hidden_at' => now()]);

        $this->assertSame(4.33, $this->data()['avg_rating']);
    }

    public function test_the_response_rate_is_the_share_of_the_periods_reviews_the_provider_replied_to(): void
    {
        $reviews = array_map(fn ($n) => $this->makeReview($this->a['activity'], $this->customerNamed("R{$n}"), 5, 'x', ['created_at' => "2026-11-0{$n} 10:00:00"]), [1, 2, 3, 4]);
        $this->makeReview($this->a['activity'], $this->customerNamed('Old'), 5, 'x', ['created_at' => '2026-09-01 10:00:00']);   // outside the period
        foreach ([0, 1] as $i) {
            ReviewReply::create(['review_id' => $reviews[$i]->id, 'provider_id' => $this->a['provider']->id, 'text' => 'Thanks', 'is_active' => true]);
        }
        ReviewReply::create(['review_id' => $reviews[2]->id, 'provider_id' => $this->a['provider']->id, 'text' => 'Withdrawn', 'is_active' => null, 'deleted_at' => now()]);

        $this->assertSame(50, $this->data()['response_rate'], 'A deleted reply is not a reply; a review from before the period is not counted');
    }

    public function test_no_reviews_in_the_period_means_no_response_rate(): void
    {
        $this->makeReview($this->a['activity'], $this->customerNamed('Old'), 5, 'x', ['created_at' => '2026-09-01 10:00:00']);

        $d = $this->data();

        $this->assertNull($d['response_rate']);
        $this->assertSame(5, $d['avg_rating']);
    }

    // --- Top activities: from the ledger -----------------------------------------------------------------------------------

    public function test_the_top_activities_are_the_ledgers_revenue_by_activity_best_gross_first(): void
    {
        $cruise = $this->second();
        $this->fundWallet($this->a, 1000, '2026-11-10', 100);   // gross 1000, net 1000 - 100 - 90 = 810
        $this->fundWallet($this->a, 2000, '2026-11-11');        // gross 2000, net 1800
        $this->fundWallet($cruise, 5000, '2026-11-12');         // gross 5000, net 4500
        $this->fundWallet($cruise, 700, '2026-09-01');          // before the period

        $top = $this->data()['top_activities'];

        $this->assertSame([[$cruise['activity']->id, 'Sunset Cruise', 1, 5000, 4500], [$this->a['activity']->id, 'Reef Dive', 2, 3000, 2610]], array_map(fn ($t) => array_values($t), $top));
        $this->assertSame(['id', 'title', 'bookings', 'gross_revenue', 'net_revenue'], array_keys($top[0]));
    }

    public function test_the_revenue_here_is_the_revenue_in_the_earnings_report(): void
    {
        $this->fundWallet($this->a, 1000, '2026-11-10', 100);
        $this->fundWallet($this->a, 2000, '2026-11-11');

        $top = $this->data('?from=2026-01-01&to=2026-11-15')['top_activities'][0];
        $earnings = $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings')->json('data');

        $this->assertSame([$earnings['gross_revenue'], $earnings['net_revenue']], [$top['gross_revenue'], $top['net_revenue']]);
    }

    public function test_at_most_five_activities_are_listed(): void
    {
        foreach (range(1, 6) as $n) {
            $this->fundWallet($this->second("Trip {$n}"), 100 * $n, '2026-11-10');
        }

        $top = $this->data()['top_activities'];

        $this->assertCount(5, $top);
        $this->assertSame([600, 500, 400, 300, 200], array_column($top, 'gross_revenue'));
    }

    public function test_a_booking_that_is_not_completed_is_not_revenue_yet(): void
    {
        $this->booking('confirmed', '2026-11-02', 'direct', null, '2026-11-20', 900);

        $this->assertSame([], $this->data()['top_activities']);
    }

    // --- Booking sources ------------------------------------------------------------------------------------------------

    public function test_the_sources_are_the_share_of_the_periods_bookings_from_each(): void
    {
        $this->booking('confirmed', '2026-11-02', 'direct');
        $this->booking('completed', '2026-11-03', 'direct');
        $this->booking('confirmed', '2026-11-04', 'social');
        $this->booking('confirmed', '2026-11-05', 'category');
        $this->booking('cancelled', '2026-11-06', 'recommendations');   // not a booking

        $this->assertSame([50, 25, 0, 25], array_column($this->data()['booking_sources'], 'percent'));
        $this->assertSame(['direct', 'category', 'recommendations', 'social'], array_column($this->data()['booking_sources'], 'source'));
    }

    public function test_the_shares_always_add_up_to_exactly_one_hundred(): void
    {
        foreach (['direct', 'category', 'social'] as $source) {
            $this->booking('confirmed', '2026-11-02', $source);
        }
        $this->assertSame([34, 33, 0, 33], array_column($this->data()['booking_sources'], 'percent'), 'Three equal thirds: the leftover point goes to the first');

        foreach (range(1, 4) as $i) {
            $this->booking('confirmed', '2026-11-03', 'recommendations');
        }
        $percent = array_column($this->data()['booking_sources'], 'percent');
        $this->assertSame(100, array_sum($percent));
    }

    public function test_a_booking_with_no_recorded_source_counts_as_direct(): void
    {
        $this->booking('confirmed', '2026-11-02', null);
        $this->booking('confirmed', '2026-11-03', 'social');

        $this->assertSame([50, 0, 0, 50], array_column($this->data()['booking_sources'], 'percent'));
    }

    // --- One activity, someone else's data --------------------------------------------------------------------------------

    public function test_everything_can_be_limited_to_one_activity(): void
    {
        $cruise = $this->second();
        $this->views(10, '2026-11-01');
        $this->views(4, '2026-11-01', 'social', $cruise);
        $this->booking('confirmed', '2026-11-02', 'direct');
        $this->booking('confirmed', '2026-11-02', 'social', $cruise);
        $this->fundWallet($this->a, 1000, '2026-11-10');
        $this->fundWallet($cruise, 400, '2026-11-10');
        $this->makeReview($cruise['activity'], $this->customerNamed('Fan'), 3);

        $d = $this->data('?activity_id='.$cruise['activity']->id);

        // Two bookings of the cruise were made in the period: the social one, and the completed one the wallet setup made (its source was not recorded: direct).
        $this->assertSame([4, 50, 3], [$d['page_views'], $d['conversion_rate'], $d['avg_rating']]);
        $this->assertSame([400], array_column($d['top_activities'], 'gross_revenue'));
        $this->assertSame([50, 0, 0, 50], array_column($d['booking_sources'], 'percent'));
    }

    public function test_someone_elses_activity_and_a_missing_one_are_answered_alike(): void
    {
        $other = $this->approvedProvider();

        $foreign = $this->report('?activity_id='.$other['activity']->id)->assertNotFound();
        $missing = $this->report('?activity_id=99999999')->assertNotFound();

        $this->assertSame('RESOURCE_NOT_FOUND', $foreign->json('code'));
        $this->assertSame($foreign->json('message'), $missing->json('message'), 'It must not reveal which ids exist');
    }

    public function test_only_the_providers_own_figures_are_counted(): void
    {
        $other = $this->approvedProvider(['commission_rate' => 0]);
        $this->views(7, '2026-11-01', 'social', $other);
        $this->booking('confirmed', '2026-11-02', 'social', $other);
        $this->fundWallet($other, 9999, '2026-11-10');
        $this->makeReview($other['activity'], $this->customerNamed('Theirs'), 1);

        $mine = $this->data();
        $theirs = $this->data('', $other);

        $this->assertSame([0, [], null], [$mine['page_views'], $mine['top_activities'], $mine['avg_rating']]);
        $this->assertSame([7, [9999], 1], [$theirs['page_views'], array_column($theirs['top_activities'], 'gross_revenue'), $theirs['avg_rating']]);
    }

    // --- Asking properly ----------------------------------------------------------------------------------------------

    public function test_a_bad_period_is_a_validation_error(): void
    {
        foreach (['?from=yesterday', '?to=15/11/2026', '?from=2026-11-10&to=2026-11-01', '?from=2025-11-14&to=2026-11-15', '?activity_id=abc', '?activity_id=0'] as $bad) {
            $this->report($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }

        $this->report('?from=2025-11-15&to=2026-11-15')->assertOk();   // a year: the longest allowed
    }

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        $this->report('', $this->unapprovedProvider())->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'GET', '/api/v1/provider/analytics')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'GET', '/api/v1/provider/analytics')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
