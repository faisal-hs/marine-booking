<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Activity;
use App\Models\Customer;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * Availability as a whole MONTH: every day of it, the days that cannot be booked too (past, closed, full), and for each departure its id, its times, the places left and whether it
 * can be booked. The activity runs on Saturdays 09:00 to 13:00; today is Sunday 15 November 2026. Saturdays still to come: 21 and 28 November, then 5, 12, 19 and 26 December.
 */
class CustomerAppAvailabilityMonthTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider();
        [, $this->customer] = $this->signedInCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function availability(array $query = [], ?Activity $activity = null)
    {
        return $this->ca(null, 'GET', '/activities/'.($activity ?? $this->a['activity'])->id.'/availability', $query);
    }

    private function days(array $query = [], ?Activity $activity = null): array
    {
        return collect($this->availability($query, $activity)->assertOk()->json('data.days'))->keyBy('date')->all();
    }

    private function bookPlaces(int $places, string $date = self::NEXT_SATURDAY): void
    {
        app(ReservationService::class)->create($this->customer, $this->a['activity'], $this->a['option'], $this->a['activity']->schedulePeriods()->first(), \Illuminate\Support\Carbon::parse($date), ['adult' => $places]);
    }

    // --- The month -----------------------------------------------------------------------------------------------------

    public function test_with_nothing_asked_it_is_every_day_of_the_current_month(): void
    {
        $data = $this->availability()->assertOk()->json('data');

        $this->assertSame(['timezone', 'from', 'to', 'booking_option', 'max_guests', 'days'], array_keys($data));
        $this->assertSame(['2026-11-01', '2026-11-30', 30], [$data['from'], $data['to'], count($data['days'])]);
        $this->assertSame(range(1, 30), array_map(fn ($d) => (int) substr($d['date'], 8), $data['days']), 'Every day, in order');
        $this->assertSame('Asia/Riyadh', $data['timezone']);
    }

    public function test_days_that_cannot_be_booked_are_listed_as_not_available_with_no_slots(): void
    {
        $days = $this->days();

        foreach (['2026-11-01', '2026-11-14', '2026-11-15', '2026-11-16', '2026-11-30'] as $date) {   // gone, today (a Sunday: nothing runs), and days nothing runs
            $this->assertSame([false, []], [$days[$date]['is_available'], $days[$date]['slots']], $date);
        }
        $this->assertTrue($days['2026-11-21']['is_available']);
        $this->assertTrue($days['2026-11-28']['is_available']);
    }

    public function test_a_departure_has_its_id_its_times_the_places_left_and_whether_it_can_be_booked(): void
    {
        $slot = $this->days()['2026-11-21']['slots'][0];

        $this->assertSame(['id', 'time', 'end_time', 'spots_left', 'is_available'], array_keys($slot));
        $this->assertSame([$this->a['period'], '09:00', '13:00', null, true], [$slot['id'], $slot['time'], $slot['end_time'], $slot['spots_left'], $slot['is_available']], 'No limit: spots_left is null');
    }

    public function test_another_month_can_be_asked_for_up_to_a_year_ahead(): void
    {
        $data = $this->availability(['month' => '2026-12'])->assertOk()->json('data');
        $days = collect($data['days'])->keyBy('date');

        $this->assertSame(['2026-12-01', '2026-12-31', 31], [$data['from'], $data['to'], count($data['days'])]);
        $this->assertSame(['2026-12-05', '2026-12-12', '2026-12-19', '2026-12-26'], $days->filter(fn ($d) => $d['is_available'])->keys()->all());
        $this->assertSame(28, count($this->availability(['month' => '2027-02'])->json('data.days')), 'February is as long as it is');
        $this->availability(['month' => '2027-11'])->assertOk();   // the last month that may be asked for
    }

    public function test_a_month_that_is_over_or_too_far_ahead_or_not_a_month_is_refused(): void
    {
        foreach (['2026-10', '2025-01', '2027-12', '2026-13', '2026-1', 'december', '2026-12-01'] as $bad) {
            $this->availability(['month' => $bad])->assertStatus(422)->assertJsonValidationErrors('month');
        }
    }

    public function test_a_window_still_works_and_wins_over_a_month(): void
    {
        $window = $this->availability(['from' => '2026-11-20', 'to' => '2026-11-22', 'month' => '2026-12'])->assertOk()->json('data');

        $this->assertSame(['2026-11-20', '2026-11-22', 3], [$window['from'], $window['to'], count($window['days'])]);
        $this->availability(['from' => '2026-11-01', 'to' => '2027-01-15'])->assertStatus(422)->assertJsonValidationErrors('to');   // more than 62 days
    }

    // --- The places ----------------------------------------------------------------------------------------------------

    public function test_the_places_left_are_those_of_the_option_and_a_full_departure_is_listed_but_not_available(): void
    {
        $this->a['option']->update(['max_participants' => 5]);
        $this->bookPlaces(3);

        $days = $this->days();
        $this->assertSame([2, true], [$days['2026-11-21']['slots'][0]['spots_left'], $days['2026-11-21']['slots'][0]['is_available']]);
        $this->assertSame(5, $this->availability()->json('data.max_guests'));

        $this->bookPlaces(2);
        $full = $this->days()['2026-11-21'];
        $this->assertSame([0, false, false, 1], [$full['slots'][0]['spots_left'], $full['slots'][0]['is_available'], $full['is_available'], count($full['slots'])], 'Listed, as full');
        $this->assertTrue($this->days()['2026-11-28']['is_available'], 'The next Saturday is another departure');
    }

    public function test_the_places_of_another_price_option_can_be_asked_for_and_units_are_counted_in_units(): void
    {
        $units = $this->a['activity']->bookingOptions()->create(['name' => 'Kayaks', 'slug' => 'kayaks-'.uniqid(), 'booking_model' => 'per_unit', 'min_quantity' => 1, 'max_quantity' => 4, 'sort_order' => 2]);
        $units->pricingRules()->create(['participant_type' => null, 'price' => 300]);
        app(ReservationService::class)->create($this->customer, $this->a['activity'], $units, $this->a['activity']->schedulePeriods()->first(), \Illuminate\Support\Carbon::parse(self::NEXT_SATURDAY), null, 3);

        $data = $this->availability(['booking_option_id' => $units->id])->assertOk()->json('data');
        $slot = collect($data['days'])->firstWhere('date', '2026-11-21')['slots'][0];

        $this->assertSame(['id' => $units->id, 'name' => 'Kayaks', 'reservation_type' => 'units'], $data['booking_option']);
        $this->assertSame([1, true], [$slot['spots_left'], $slot['is_available']], 'One unit of four is left');
        $this->assertSame($this->a['option']->id, $this->availability()->json('data.booking_option.id'), 'Without the option, the default one');
    }

    public function test_an_option_that_is_off_or_not_the_activitys_is_refused(): void
    {
        $other = $this->moreActivity($this->a, 'Another', 100)->bookingOptions()->first();

        foreach ([999999, $other->id] as $id) {
            $this->availability(['booking_option_id' => $id])->assertStatus(422)->assertJsonValidationErrors('booking_option_id');
        }
    }

    // --- Who -----------------------------------------------------------------------------------------------------------

    public function test_it_is_open_to_anyone_and_only_for_activities_that_can_be_booked(): void
    {
        $this->availability()->assertOk();

        $draft = $this->moreActivity($this->a, 'Draft', 100);
        $draft->update(['status' => 'draft']);
        $this->availability([], $draft)->assertNotFound();
        $this->ca(null, 'GET', '/activities/99999/availability')->assertNotFound();
    }
}
