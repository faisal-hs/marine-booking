<?php

namespace Tests\Feature\ProviderApp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/** GET /provider/calendar?month=YYYY-MM: the days of a month that have bookings or are blocked. */
class ProviderCalendarApiTest extends TestCase
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

    private function month(string $month = '2026-11', ?array $as = null): array
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', "/api/v1/provider/calendar?month={$month}")->assertOk()->json('data');
    }

    /** The days of a month that have a booking on them or are closed: the days the calendar used to list on its own, picked out of the whole month. */
    private function eventful(string $month = '2026-11', ?array $as = null): array
    {
        return array_values(array_filter($this->month($month, $as), fn ($day) => $day['bookings_count'] > 0 || $day['is_blocked']));
    }

    private function close(int $activityId, string $from, string $to, array $overrides = []): void
    {
        DB::table('activity_availability_overrides')->insert(array_merge(['uuid' => (string) Str::uuid(), 'activity_id' => $activityId, 'date_from' => $from, 'date_to' => $to,
            'is_unavailable' => 1, 'price' => null, 'instructions' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], $overrides));
    }

    public function test_the_days_with_bookings_are_counted_in_date_order_and_only_real_bookings_count(): void
    {
        $customer = $this->customerNamed('Fatima');
        foreach ([['2026-11-16', 'confirmed'], ['2026-11-15', 'confirmed'], ['2026-11-15', 'pending'], ['2026-11-15', 'cancelled'], ['2026-11-10', 'completed'],
            ['2026-11-12', 'expired'], ['2026-11-11', 'cancelled'], ['2026-10-30', 'confirmed'], ['2026-12-01', 'confirmed']] as [$date, $status]) {
            $this->makeBooking($this->a, $customer, $date, $status);
        }

        $this->assertSame([
            ['date' => '2026-11-10', 'bookings_count' => 1, 'is_blocked' => false],
            ['date' => '2026-11-15', 'bookings_count' => 2, 'is_blocked' => false],
            ['date' => '2026-11-16', 'bookings_count' => 1, 'is_blocked' => false],
        ], $this->eventful(), 'Cancelled and expired are not bookings; other months are not listed');
    }

    public function test_a_day_is_blocked_when_every_published_activity_is_closed_that_day(): void
    {
        $this->close($this->a['activity']->id, '2026-11-18', '2026-11-19');
        $this->makeBooking($this->a, $this->customerNamed('Fatima'), '2026-11-18');

        $this->assertSame([
            ['date' => '2026-11-18', 'bookings_count' => 1, 'is_blocked' => true],
            ['date' => '2026-11-19', 'bookings_count' => 0, 'is_blocked' => true],
        ], $this->eventful(), 'A blocked day with no bookings is listed, and a blocked day keeps its count');
    }

    public function test_one_open_activity_keeps_the_day_open(): void
    {
        [$second] = $this->makeBookableActivity($this->a['provider'], 'published', ['title' => 'Sunset Cruise']);
        $this->close($this->a['activity']->id, '2026-11-18', '2026-11-18');

        $this->assertSame([], $this->eventful(), 'Only one of the two is closed');

        $this->close($second->id, '2026-11-18', '2026-11-18');
        $this->assertSame([['date' => '2026-11-18', 'bookings_count' => 0, 'is_blocked' => true]], $this->eventful(), 'Both closed');
    }

    public function test_only_closing_overrides_that_are_active_block_a_day(): void
    {
        $this->close($this->a['activity']->id, '2026-11-18', '2026-11-18', ['is_unavailable' => 0, 'price' => 900]);   // a special price, not a closure
        $this->close($this->a['activity']->id, '2026-11-19', '2026-11-19', ['status' => 'inactive']);

        $this->assertSame([], $this->eventful());
    }

    public function test_activities_that_are_not_published_do_not_decide_whether_a_day_is_blocked(): void
    {
        $draft = $this->makeActivity($this->a['provider'], 'draft');
        $this->close($this->a['activity']->id, '2026-11-18', '2026-11-18');
        $this->close($draft->id, '2026-11-25', '2026-11-25');

        $this->assertSame([['date' => '2026-11-18', 'bookings_count' => 0, 'is_blocked' => true]], $this->eventful(), 'The draft is open on the 18th and closed on the 25th, and counts for neither');
    }

    public function test_a_provider_with_nothing_published_has_no_blocked_days(): void
    {
        $this->a['activity']->update(['status' => 'draft']);
        $this->close($this->a['activity']->id, '2026-11-18', '2026-11-18');

        $this->assertSame([], $this->eventful());
    }

    public function test_an_override_that_spans_months_is_clipped_to_the_month_asked_for(): void
    {
        $this->close($this->a['activity']->id, '2026-10-30', '2026-11-02');

        $this->assertSame([['date' => '2026-11-01', 'bookings_count' => 0, 'is_blocked' => true], ['date' => '2026-11-02', 'bookings_count' => 0, 'is_blocked' => true]], $this->eventful('2026-11'));
        $this->assertSame([['date' => '2026-10-30', 'bookings_count' => 0, 'is_blocked' => true], ['date' => '2026-10-31', 'bookings_count' => 0, 'is_blocked' => true]], $this->eventful('2026-10'));
    }

    public function test_bookings_on_the_first_and_last_day_of_a_month_are_counted(): void
    {
        $customer = $this->customerNamed('Fatima');
        $this->makeBooking($this->a, $customer, '2027-02-01');
        $this->makeBooking($this->a, $customer, '2027-02-28');
        $this->makeBooking($this->a, $customer, '2027-03-01');

        $this->assertSame(['2027-02-01', '2027-02-28'], array_column($this->eventful('2027-02'), 'date'), 'The 1st of March belongs to March');
    }

    // --- Every day of the month --------------------------------------------------------------------------------------

    public function test_every_day_of_the_month_is_listed_from_the_first_to_the_last_in_order(): void
    {
        $days = $this->month('2026-11');

        $this->assertCount(30, $days);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$days[0]['date'], $days[29]['date']]);
        $this->assertSame(array_map(fn ($d) => sprintf('2026-11-%02d', $d), range(1, 30)), array_column($days, 'date'), 'Consecutive, no gap and no repeat');
        $this->assertSame(['date', 'bookings_count', 'is_blocked'], array_keys($days[0]));
    }

    public function test_a_month_with_nothing_in_it_is_still_every_day_with_zero_and_open(): void
    {
        foreach ($this->month('2026-11') as $day) {
            $this->assertSame([0, false], [$day['bookings_count'], $day['is_blocked']], $day['date']);
        }
    }

    public function test_a_provider_with_no_activities_at_all_still_gets_the_whole_month(): void
    {
        $this->a['activity']->delete();

        $this->assertCount(30, $this->month('2026-11'));
        $this->assertSame([], $this->eventful('2026-11'));
    }

    public function test_the_length_of_the_month_is_right_for_every_kind_of_month(): void
    {
        foreach (['2026-10' => 31, '2026-11' => 30, '2026-12' => 31, '2027-02' => 28, '2028-02' => 29, '2100-02' => 28, '2000-02' => 29, '2035-01' => 31] as $month => $length) {
            $days = $this->month($month);

            $this->assertCount($length, $days, $month);
            $this->assertSame(sprintf('%s-%02d', $month, $length), end($days)['date'], "The last day of {$month}");
        }
    }

    public function test_the_days_with_something_are_in_their_own_place_among_the_empty_ones(): void
    {
        $this->makeBooking($this->a, $this->customerNamed('Fatima'), '2026-11-15');
        $this->makeBooking($this->a, $this->customerNamed('Fatima'), '2026-11-15');
        $this->makeBooking($this->a, $this->customerNamed('Noura'), '2026-11-30');
        $this->close($this->a['activity']->id, '2026-11-01', '2026-11-02');

        $days = $this->month('2026-11');

        $this->assertSame(['2026-11-15', 2, false], [$days[14]['date'], $days[14]['bookings_count'], $days[14]['is_blocked']]);
        $this->assertSame(['2026-11-30', 1], [$days[29]['date'], $days[29]['bookings_count']], 'The last day shows its booking');
        $this->assertSame([true, true, false], [$days[0]['is_blocked'], $days[1]['is_blocked'], $days[2]['is_blocked']]);
        $this->assertSame(30, count($days), 'The month is still whole');
    }

    public function test_the_month_is_required_and_must_be_year_dash_month(): void
    {
        $this->hit($this->a['token'], 'GET', '/api/v1/provider/calendar')->assertStatus(422)->assertJsonValidationErrors('month');

        foreach (['2026-13', '11-2026', '2026-1', '2026-11-15', 'november', '2026/11'] as $bad) {
            $this->hit($this->a['token'], 'GET', "/api/v1/provider/calendar?month={$bad}")->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_another_providers_bookings_and_closures_are_not_in_your_calendar(): void
    {
        $other = $this->approvedProvider();
        $this->makeBooking($other, $this->customerNamed('Someone Else'), '2026-11-15');
        $this->close($other['activity']->id, '2026-11-18', '2026-11-18');

        $this->assertSame([], $this->eventful());
        $this->assertCount(30, $this->month());
        $this->assertSame([['date' => '2026-11-15', 'bookings_count' => 1, 'is_blocked' => false], ['date' => '2026-11-18', 'bookings_count' => 0, 'is_blocked' => true]], $this->eventful('2026-11', $other));
    }
}
