<?php

namespace Tests\Feature\ProviderApp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/bookings?filter=this_week and ?date=YYYY-MM-DD. Today is Sunday 15 November 2026, and a week starts on Sunday: the current week is 15 to
 * 21 November. Both filters are about the day of the TRIP, and show real bookings (not cancelled or expired) unless a status is asked for, like "today".
 */
class ProviderBookingsPeriodFilterTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function book(string $date, string $status = 'confirmed', ?int $period = null, ?array $scenario = null): int
    {
        return $this->makeBooking($scenario ?? $this->a, $this->customerNamed('C'.uniqid()), $date, $status, 1000, $period ? ['schedule_period_id' => $period] : []);
    }

    /** @return list<int> */
    private function ids(string $query, ?array $as = null): array
    {
        return array_column($this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/bookings'.$query)->assertOk()->json('data.bookings'), 'id');
    }

    // --- This week -----------------------------------------------------------------------------------------------------

    public function test_this_week_is_the_bookings_whose_trip_is_in_the_current_week_soonest_first(): void
    {
        $lastWeek = $this->book('2026-11-14');                // the Saturday before
        $sunday = $this->book('2026-11-15', 'pending');
        $tuesday = $this->book('2026-11-17');
        $saturday = $this->book('2026-11-21', 'completed');   // the last day of the week
        $nextWeek = $this->book('2026-11-22');                // the Sunday after

        $this->assertSame([$sunday, $tuesday, $saturday], $this->ids('?filter=this_week'));
        $this->assertNotContains($lastWeek, $this->ids('?filter=this_week'));
        $this->assertNotContains($nextWeek, $this->ids('?filter=this_week'));
    }

    public function test_cancelled_and_expired_bookings_are_left_out_unless_a_status_is_asked_for(): void
    {
        $real = $this->book('2026-11-17');
        $cancelled = $this->book('2026-11-18', 'cancelled');
        $expired = $this->book('2026-11-19', 'expired');

        $this->assertSame([$real], $this->ids('?filter=this_week'));
        $this->assertSame([$cancelled], $this->ids('?filter=this_week&status=cancelled'));
        $this->assertSame([$expired], $this->ids('?filter=this_week&status=expired'));
        $this->assertSame([$real], $this->ids('?filter=this_week&status=confirmed'));
    }

    public function test_within_a_day_the_earlier_departure_comes_first(): void
    {
        $evening = $this->a['activity']->schedulePeriods()->create(['day_of_week' => 2, 'start_time' => '18:00', 'end_time' => '20:00']);
        $morning = $this->a['activity']->schedulePeriods()->create(['day_of_week' => 2, 'start_time' => '07:00', 'end_time' => '09:00']);
        $late = $this->book('2026-11-17', 'confirmed', $evening->id);
        $early = $this->book('2026-11-17', 'confirmed', $morning->id);
        $next = $this->book('2026-11-16');

        $this->assertSame([$next, $early, $late], $this->ids('?filter=this_week'));
    }

    public function test_the_week_follows_the_day_it_starts_on_and_moves_on_with_the_calendar(): void
    {
        $sat = $this->book('2026-11-14');
        $sun = $this->book('2026-11-15');
        $mon = $this->book('2026-11-16');
        $next = $this->book('2026-11-22');

        $this->assertSame([$sun, $mon], $this->ids('?filter=this_week'));

        config(['platform.week_starts_on' => 1]);   // a week starting on Monday: today (a Sunday) is its last day
        $this->assertSame([$sat, $sun], $this->ids('?filter=this_week'));

        config(['platform.week_starts_on' => 6]);   // starting on Saturday: 14 to 20 November
        $this->assertSame([$sat, $sun, $mon], $this->ids('?filter=this_week'));

        config(['platform.week_starts_on' => 0]);
        foreach (['2026-11-18 09:00:00' => [$sun, $mon], '2026-11-21 23:30:00' => [$sun, $mon], '2026-11-22 00:30:00' => [$next]] as $now => $expected) {
            Carbon::setTestNow($now);
            $this->assertSame($expected, $this->ids('?filter=this_week'), "On {$now}");
        }
    }

    public function test_this_week_combines_with_the_other_filters(): void
    {
        $tue = $this->book('2026-11-17');
        $thu = $this->book('2026-11-19');
        $sat = $this->book('2026-11-21');

        $this->assertSame([$thu, $sat], $this->ids('?filter=this_week&from=2026-11-18'));
        $this->assertSame([$tue, $thu], $this->ids('?filter=this_week&to=2026-11-19'));
        $this->assertSame([$thu], $this->ids('?filter=this_week&date=2026-11-19'));
        $this->assertSame([], $this->ids('?filter=this_week&date=2026-11-25'), 'A day outside the week has nothing in it');
    }

    public function test_it_pages_and_only_shows_this_providers_bookings(): void
    {
        $other = $this->approvedProvider();
        $this->book('2026-11-17', 'confirmed', null, $other);
        foreach (range(1, 5) as $i) {
            $this->book('2026-11-1'.(5 + $i % 5));
        }

        $r = $this->hit($this->a['token'], 'GET', '/api/v1/provider/bookings?filter=this_week&per_page=2&page=3')->assertOk();

        $this->assertSame(['current_page' => 3, 'last_page' => 3, 'per_page' => 2, 'total' => 5], $r->json('meta'));
        $this->assertCount(1, $r->json('data.bookings'));
    }

    // --- A single date ----------------------------------------------------------------------------------------------------

    public function test_a_date_shows_the_bookings_whose_trip_is_that_day(): void
    {
        $this->book('2026-11-16');
        $a = $this->book('2026-11-17');
        $b = $this->book('2026-11-17', 'pending');
        $this->book('2026-11-18');

        $this->assertEqualsCanonicalizing([$a, $b], $this->ids('?date=2026-11-17'));
        $this->assertSame([], $this->ids('?date=2026-11-20'));
    }

    public function test_a_date_works_for_the_past_and_the_future_alike(): void
    {
        $past = $this->book('2026-10-01', 'completed');
        $future = $this->book('2027-02-03');

        $this->assertSame([$past], $this->ids('?date=2026-10-01'));
        $this->assertSame([$future], $this->ids('?date=2027-02-03'));
    }

    public function test_a_date_shows_real_bookings_unless_a_status_is_asked_for(): void
    {
        $real = $this->book('2026-11-17');
        $cancelled = $this->book('2026-11-17', 'cancelled');
        $expired = $this->book('2026-11-17', 'expired');

        $this->assertSame([$real], $this->ids('?date=2026-11-17'));
        $this->assertSame([$cancelled], $this->ids('?date=2026-11-17&status=cancelled'));
        $this->assertSame([$expired], $this->ids('?date=2026-11-17&status=expired'));
    }

    public function test_on_a_date_the_earlier_departure_comes_first(): void
    {
        $evening = $this->a['activity']->schedulePeriods()->create(['day_of_week' => 2, 'start_time' => '18:00', 'end_time' => '20:00']);
        $morning = $this->a['activity']->schedulePeriods()->create(['day_of_week' => 2, 'start_time' => '07:00', 'end_time' => '09:00']);
        $late = $this->book('2026-11-17', 'confirmed', $evening->id);
        $early = $this->book('2026-11-17', 'confirmed', $morning->id);

        $this->assertSame([$early, $late], $this->ids('?date=2026-11-17'));
    }

    public function test_a_date_combines_with_the_older_filters_and_an_empty_one_means_none(): void
    {
        $today = $this->book('2026-11-15', 'confirmed');
        $pending = $this->book('2026-11-15', 'pending');
        $tomorrow = $this->book('2026-11-16');

        $this->assertSame([$pending], $this->ids('?date=2026-11-15&filter=pending'));
        $this->assertEqualsCanonicalizing([$today, $pending], $this->ids('?date=2026-11-15&filter=today'));
        $this->assertSame([], $this->ids('?date=2026-11-16&filter=today'), 'Both must hold');
        $this->assertSame([$tomorrow], $this->ids('?date=2026-11-16&from=2026-11-16&to=2026-11-16'));
        $this->assertCount(3, $this->ids('?date=&filter=&status='), 'Apps send empty values: they mean no filter');
    }

    public function test_a_date_that_is_not_a_date_is_a_validation_error(): void
    {
        foreach (['?date=tomorrow', '?date=15/11/2026', '?date=2026-13-01', '?date=2026-11-31', '?date=2026-11', '?filter=next_week', '?filter=thisweek'] as $bad) {
            $this->hit($this->a['token'], 'GET', '/api/v1/provider/bookings'.$bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_only_this_providers_bookings_are_ever_shown_for_a_date(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->book('2026-11-17', 'confirmed', null, $other);
        $mine = $this->book('2026-11-17');

        $this->assertSame([$mine], $this->ids('?date=2026-11-17'));
        $this->assertSame([$theirs], $this->ids('?date=2026-11-17', $other));
    }

    public function test_the_filters_are_for_approved_providers_only(): void
    {
        $this->hit($this->unapprovedProvider()['token'], 'GET', '/api/v1/provider/bookings?filter=this_week')->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'GET', '/api/v1/provider/bookings?date=2026-11-17')->assertForbidden();
        $this->hit(null, 'GET', '/api/v1/provider/bookings?date=2026-11-17')->assertUnauthorized();
    }
}
