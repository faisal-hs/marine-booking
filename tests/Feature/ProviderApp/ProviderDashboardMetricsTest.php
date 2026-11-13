<?php

namespace Tests\Feature\ProviderApp;

use App\Models\ReviewReply;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * What the dashboard says about trends and performance. Today is Sunday 15 November 2026; weeks start on Sunday, so the current week is 15 to 21 November
 * and the four weeks shown start on 25 October, 1, 8 and 15 November. Revenue is the price of the trips that took place (the provider's commission is 10%).
 */
class ProviderDashboardMetricsTest extends TestCase
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

    private function dash(?array $as = null): array
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/dashboard')->assertOk()->json('data');
    }

    private function fund(float $total, string $date, ?array $as = null): void
    {
        $this->fundWallet($as ?? $this->a, $total, $date);
    }

    private function booking(string $status, string $date = '2026-11-20', ?string $createdAt = null, ?string $name = null): int
    {
        return $this->makeBooking($this->a, $this->customerNamed($name ?? 'C'.uniqid()), $date, $status, 1000, $createdAt ? ['created_at' => $createdAt, 'updated_at' => $createdAt] : []);
    }

    // --- The new fields -----------------------------------------------------------------------------------------------------

    public function test_the_new_fields_have_the_shape_of_the_example(): void
    {
        $d = $this->dash();

        $this->assertSame(['gross', 'net', 'change_percent'], array_keys($d['month_revenue']));
        $this->assertCount(4, $d['weekly_revenue']);
        $this->assertSame(['booking_rate', 'response_rate', 'cancellation_rate', 'repeat_customers'], array_keys($d['performance']));
        $this->assertSame(['5', '4', '3', '2', '1'], array_map('strval', array_keys($d['rating_distribution'])));
        $this->assertStringContainsString('"rating_distribution":{"5":0,"4":0,"3":0,"2":0,"1":0}', $this->hit($this->a['token'], 'GET', '/api/v1/provider/dashboard')->getContent(), 'An object keyed by the star');
        $this->assertSame([0, 0, null, null, 0], [$d['today_revenue'], $d['month_bookings_change'], $d['today_revenue_change_percent'], $d['month_revenue']['change_percent'], $d['month_bookings']]);
    }

    // --- Weekly revenue -------------------------------------------------------------------------------------------------------

    public function test_weekly_revenue_is_the_last_four_weeks_oldest_first_and_the_current_week_last(): void
    {
        $this->fund(300, '2026-10-28');   // the week of 25 October
        $this->fund(500, '2026-11-03');   // 1 November
        $this->fund(700, '2026-11-07');   // the last day of that week
        $this->fund(2000, '2026-11-10');  // 8 November
        $this->fund(1000, '2026-11-15');  // this week, today
        $this->fund(9999, '2026-10-20');  // a week too early: not shown
        $this->fund(8888, '2026-11-22');  // next week: not yet

        $this->assertEquals([300, 1200, 2000, 1000], $this->dash()['weekly_revenue']);
    }

    public function test_the_day_a_week_starts_on_is_a_setting(): void
    {
        $this->fund(9999, '2026-10-20');
        $this->fund(300, '2026-10-28');
        $this->fund(500, '2026-11-03');
        $this->fund(2000, '2026-11-10');
        $this->fund(1000, '2026-11-15');   // a Sunday: the LAST day of a Monday week

        config(['platform.week_starts_on' => 1]);

        // Weeks of 19 October, 26 October, 2 November and 9 November (to today).
        $this->assertEquals([9999, 300, 500, 3000], $this->dash()['weekly_revenue']);
    }

    public function test_weekly_revenue_is_gross_not_net_and_leaves_out_other_providers_and_unfinished_trips(): void
    {
        $other = $this->approvedProvider(['commission_rate' => 0]);
        $this->fund(1000, '2026-11-10');
        $this->fund(5000, '2026-11-10', $other);
        $this->makeBooking($this->a, $this->customerNamed('Not yet'), '2026-11-10', 'confirmed', 7777);

        $this->assertEquals([0, 0, 1000, 0], $this->dash()['weekly_revenue'], 'The price (1,000), not what is left after the 10% commission');
    }

    // --- Month revenue and its change -----------------------------------------------------------------------------------------

    public function test_the_month_change_compares_this_month_so_far_with_the_same_days_of_last_month(): void
    {
        $this->fund(600, '2026-11-03');
        $this->fund(400, '2026-11-10');   // 1,000 so far this month
        $this->fund(500, '2026-10-05');
        $this->fund(300, '2026-10-14');   // 800 in 1 to 15 October
        $this->fund(5000, '2026-10-20');  // later in October: not part of the same days

        $this->assertSame(25, $this->dash()['month_revenue']['change_percent']);
    }

    public function test_a_fall_is_negative(): void
    {
        $this->fund(250, '2026-11-03');
        $this->fund(1000, '2026-10-05');

        $this->assertSame(-75, $this->dash()['month_revenue']['change_percent']);
    }

    public function test_with_nothing_to_compare_with_the_change_is_null_not_zero_or_infinite(): void
    {
        $this->fund(600, '2026-11-03');

        $this->assertNull($this->dash()['month_revenue']['change_percent']);
    }

    public function test_the_thirty_first_compares_with_the_whole_of_a_shorter_month(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');
        $this->fund(100, '2026-02-28');
        $this->fund(300, '2026-03-05');

        $this->assertSame(200, $this->dash()['month_revenue']['change_percent'], 'February has 28 days: all of it is compared');
    }

    // --- Today ----------------------------------------------------------------------------------------------------------------

    public function test_todays_revenue_and_its_change_against_yesterday(): void
    {
        $this->fund(1800, '2026-11-15');
        $this->fund(1500, '2026-11-14');
        $this->fund(9999, '2026-11-13');

        $d = $this->dash();

        $this->assertEquals([1800, 20], [$d['today_revenue'], $d['today_revenue_change_percent']]);
    }

    public function test_a_quiet_day_after_a_busy_one_is_minus_a_hundred_and_after_a_quiet_one_is_null(): void
    {
        $this->fund(1500, '2026-11-14');
        $d = $this->dash();
        $this->assertEquals([0, -100], [$d['today_revenue'], $d['today_revenue_change_percent']]);

        $this->fund(300, '2026-11-15');
        $this->assertSame(-80, $this->dash()['today_revenue_change_percent']);

        Carbon::setTestNow('2026-11-17 12:00:00');
        $this->fund(100, '2026-11-17');
        $this->assertNull($this->dash()['today_revenue_change_percent'], 'Yesterday earned nothing, so there is no change to state');
    }

    // --- Bookings against last month ----------------------------------------------------------------------------------------

    public function test_the_bookings_change_is_how_many_more_or_fewer_than_the_whole_of_last_month(): void
    {
        foreach (['2026-11-02', '2026-11-20', '2026-11-28'] as $date) {
            $this->booking('confirmed', $date);
        }
        foreach (['2026-10-03', '2026-10-30'] as $date) {
            $this->booking('completed', $date);
        }
        $this->booking('cancelled', '2026-11-05');
        $this->booking('expired', '2026-10-05');

        $d = $this->dash();

        $this->assertSame([3, 1], [$d['month_bookings'], $d['month_bookings_change']], 'Trips still to come this month count');

        foreach (['2026-10-07', '2026-10-08', '2026-10-09'] as $date) {
            $this->booking('confirmed', $date);
        }
        $this->assertSame(-2, $this->dash()['month_bookings_change']);
    }

    public function test_with_no_bookings_last_month_the_change_is_this_months_whole_count(): void
    {
        $this->booking('confirmed', '2026-11-20');
        $this->booking('pending', '2026-11-21');

        $this->assertSame(2, $this->dash()['month_bookings_change']);
    }

    // --- Performance --------------------------------------------------------------------------------------------------------------

    public function test_the_booking_and_cancellation_rates_are_of_the_bookings_made_in_the_last_thirty_days(): void
    {
        $this->booking('confirmed');
        $this->booking('completed');
        $this->booking('cancelled');
        $this->booking('expired');
        $this->booking('pending');
        $this->booking('cancelled', '2026-11-20', '2026-10-01 10:00:00');   // made 45 days ago: not counted
        $this->booking('confirmed', '2026-11-20', '2026-10-01 10:00:00');

        $p = $this->dash()['performance'];

        $this->assertSame([40, 20], [$p['booking_rate'], $p['cancellation_rate']], '2 of 5 were kept; 1 of 5 was cancelled');
    }

    public function test_a_booking_made_exactly_thirty_days_ago_is_still_counted(): void
    {
        $this->booking('confirmed', '2026-11-20', '2026-10-16 12:00:00');
        $this->booking('cancelled', '2026-11-20', '2026-10-16 11:59:59');   // a second too old

        $this->assertSame([100, 0], [$this->dash()['performance']['booking_rate'], $this->dash()['performance']['cancellation_rate']]);
    }

    public function test_with_no_recent_bookings_the_rates_are_null(): void
    {
        $this->booking('confirmed', '2026-11-20', '2026-09-01 10:00:00');

        $p = $this->dash()['performance'];

        $this->assertSame([null, null], [$p['booking_rate'], $p['cancellation_rate']]);
    }

    public function test_the_response_rate_is_the_share_of_the_last_thirty_days_reviews_the_provider_answered(): void
    {
        $reviews = array_map(fn ($n) => $this->makeReview($this->a['activity'], $this->customerNamed("R{$n}"), 5, 'x', ['created_at' => "2026-11-0{$n} 10:00:00"]), [1, 2, 3, 4]);
        $this->makeReview($this->a['activity'], $this->customerNamed('Old'), 5, 'x', ['created_at' => '2026-09-01 10:00:00']);
        ReviewReply::create(['review_id' => $reviews[0]->id, 'provider_id' => $this->a['provider']->id, 'text' => 'Thanks', 'is_active' => true]);
        ReviewReply::create(['review_id' => $reviews[1]->id, 'provider_id' => $this->a['provider']->id, 'text' => 'Withdrawn', 'is_active' => null, 'deleted_at' => now()]);

        $this->assertSame(25, $this->dash()['performance']['response_rate'], 'A deleted reply is not a reply; a review from before is not counted');
    }

    public function test_with_no_recent_reviews_the_response_rate_is_null(): void
    {
        $this->makeReview($this->a['activity'], $this->customerNamed('Old'), 5, 'x', ['created_at' => '2026-09-01 10:00:00']);

        $this->assertNull($this->dash()['performance']['response_rate']);
    }

    public function test_repeat_customers_are_the_share_of_customers_with_two_or_more_kept_bookings(): void
    {
        $a = $this->customerNamed('Again');
        $b = $this->customerNamed('Once');
        $c = $this->customerNamed('Often');
        $d = $this->customerNamed('Only cancelled');
        foreach ([[$a, 'confirmed'], [$a, 'completed'], [$b, 'completed'], [$c, 'completed'], [$c, 'completed'], [$c, 'confirmed'], [$d, 'cancelled'], [$d, 'cancelled'], [$d, 'expired']] as [$customer, $status]) {
            $this->makeBooking($this->a, $customer, '2026-11-20', $status, 1000);
        }

        $this->assertSame(66.7, $this->dash()['performance']['repeat_customers'], '2 of the 3 customers with a kept booking came back; the one who only cancelled is not a customer here');
    }

    public function test_repeat_customers_look_at_all_time_not_just_this_month_and_are_null_with_no_customers(): void
    {
        $this->assertNull($this->dash()['performance']['repeat_customers']);

        $customer = $this->customerNamed('Loyal');
        $this->makeBooking($this->a, $customer, '2025-03-01', 'completed', 1000);
        $this->makeBooking($this->a, $customer, '2026-11-20', 'confirmed', 1000);

        $this->assertSame(100, $this->dash()['performance']['repeat_customers']);
    }

    public function test_another_providers_numbers_are_not_mine(): void
    {
        $other = $this->approvedProvider();
        $this->makeBooking($other, $this->customerNamed('Theirs'), '2026-11-20', 'cancelled', 1000);
        $this->makeReview($other['activity'], $this->customerNamed('Their reviewer'), 1, 'bad');
        $this->fund(5000, '2026-11-15', $other);

        $d = $this->dash();

        $this->assertSame([null, null, null, null], array_values($d['performance']));
        $this->assertSame([0, 0, [0, 0, 0, 0]], [$d['today_revenue'], $d['month_bookings'], $d['weekly_revenue']]);
        $this->assertSame(['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0], $d['rating_distribution']);
    }

    // --- Rating distribution ------------------------------------------------------------------------------------------------

    private function reviews(array $stars): void
    {
        foreach ($stars as $i => $star) {
            $this->makeReview($this->a['activity'], $this->customerNamed("Reviewer {$i}"), $star, 'x');
        }
    }

    public function test_the_distribution_is_the_share_of_reviews_for_each_star_in_percent(): void
    {
        $this->reviews(array_merge(array_fill(0, 7, 5), [4, 4, 3]));

        $this->assertSame(['5' => 70, '4' => 20, '3' => 10, '2' => 0, '1' => 0], $this->dash()['rating_distribution']);
    }

    public function test_the_percentages_always_add_up_to_exactly_a_hundred(): void
    {
        $this->reviews([5, 4, 3]);   // thirds
        $third = $this->dash()['rating_distribution'];
        $this->assertSame(100, array_sum($third));
        $this->assertSame(['5' => 34, '4' => 33, '3' => 33, '2' => 0, '1' => 0], $third, 'The leftover point goes to the best star on a tie');

        $this->reviews([2, 1, 1, 5, 5, 4, 4, 3, 3, 2, 1, 5, 4]);
        $this->assertSame(100, array_sum($this->dash()['rating_distribution']));
    }

    public function test_a_review_an_admin_hid_is_not_counted_and_with_no_reviews_all_are_zero(): void
    {
        $this->assertSame(['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0], $this->dash()['rating_distribution']);

        $this->makeReview($this->a['activity'], $this->customerNamed('Kept'), 5, 'x');
        $this->makeReview($this->a['activity'], $this->customerNamed('Hidden'), 1, 'rude', ['is_hidden' => true, 'hidden_at' => now()]);

        $this->assertSame(['5' => 100, '4' => 0, '3' => 0, '2' => 0, '1' => 0], $this->dash()['rating_distribution']);
    }
}
