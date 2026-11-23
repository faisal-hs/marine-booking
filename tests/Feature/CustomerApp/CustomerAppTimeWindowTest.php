<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivitySchedulePeriod;
use App\Models\Coupon;
use App\Models\Reservation;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * Booking BY TIME. The provider sets, for a booking option, the shortest booking in MINUTES (which is also the block it grows by) and the price of that block; the customer picks a start
 * and an end inside an operating period, a whole number of blocks long: 30 minutes at 200 is 30 minutes = 200, 60 = 400, 90 = 600, 120 = 800. A period can run past midnight (20:00 to 05:00)
 * and a window can cross it. Windows of one option never overlap; a booking waiting to be paid holds its time until the hold runs out; a cancelled one gives it back.
 *
 * Today is Sunday 15 November 2026, so the next Saturday is the 21st. The scenario's own departure is Saturdays 09:00 to 13:00 (Riyadh time).
 */
class CustomerAppTimeWindowTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider();
        [, , $this->token] = $this->signedInCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An activity booked by time: blocks of `$block` minutes at `$price` each (30 at 200), from `$block` up to `$max` minutes, running `$start` to `$end` on Saturdays.
     *
     * @return array{0: Activity, 1: ActivityBookingOption, 2: ActivitySchedulePeriod}
     */
    private function timed(float $price = 200, int $block = 30, ?int $max = 240, string $start = '09:00', string $end = '13:00', string $title = 'Boat'): array
    {
        $activity = $this->moreActivity($this->a, $title, 100);
        $activity->schedulePeriods()->delete();
        $period = $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => $start, 'end_time' => $end, 'crosses_midnight' => ActivitySchedulePeriod::determinesCrossesMidnight($start, $end)]);   // as the API makes it
        $o = $activity->bookingOptions()->first();
        $o->update(['booking_model' => 'per_hour', 'is_default' => true, 'min_duration' => null, 'max_duration' => null, 'duration_unit' => null, 'min_duration_minutes' => $block, 'max_duration_minutes' => $max]);
        $o->pricingRules()->delete();
        $o->pricingRules()->create(['participant_type' => null, 'price' => $price]);

        return [$activity->refresh(), $o->refresh(), $period->refresh()];
    }

    /** A booking option booked by the hour the way it always was: whole hours, an hourly price, from 2 to 8 hours. @return array{0: Activity, 1: ActivityBookingOption, 2: ActivitySchedulePeriod} */
    private function hourly(float $price = 600): array
    {
        [$a, $o, $p] = $this->timed($price);
        $o->update(['min_duration_minutes' => null, 'max_duration_minutes' => null, 'min_duration' => 2, 'max_duration' => 8, 'duration_unit' => 'hour']);

        return [$a->refresh(), $o->refresh(), $p];
    }

    private function window(Activity $a, ActivitySchedulePeriod $p, string $from, string $to, array $over = [], string $day = self::NEXT_SATURDAY): array
    {
        $end = $to < $from ? Carbon::parse($day)->addDay()->toDateString() : $day;

        return $over + ['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => "{$day} {$from}", 'to' => "{$end} {$to}"];
    }

    private function summary(array $body)
    {
        return $this->ca($this->token, 'POST', '/bookings/summary', $body);
    }

    private function book(array $body, ?string $key = null, ?string $token = null)
    {
        return $this->ca($token ?? $this->token, 'POST', '/bookings', $body + ['contact_name' => 'Ahmed Al-Harbi', 'contact_phone' => '+966501234567', 'payment_method' => 'card', 'simulate' => 'async'],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function bookingOptionsOf(Activity $a): array
    {
        return $this->ca($this->token, 'GET', "/activities/{$a->id}/availability?month=2026-11")->assertOk()->json('data.booking_options');
    }

    private function total(array $body): float
    {
        return (float) $this->summary($body)->assertOk()->json('data.total');
    }

    // --- The booking options on the availability --------------------------------------------------------------------------

    public function test_a_booking_by_persons_shows_its_adult_and_child_price(): void
    {
        $this->a['option']->pricingRules()->create(['participant_type' => 'child', 'price' => 750]);

        $o = $this->bookingOptionsOf($this->a['activity']);

        $this->assertCount(1, $o);
        $this->assertEquals(['persons', 'per_person', 1500, 750, null, null, null, null, null, null], [$o[0]['reservation_type'], $o[0]['booking_model'], $o[0]['adult_price'], $o[0]['child_price'], $o[0]['infant_price'], $o[0]['price'],
            $o[0]['increment_minutes'], $o[0]['min_minutes'], $o[0]['max_minutes'], $o[0]['min_minutes_price']]);
    }

    public function test_a_booking_by_time_shows_the_block_the_shortest_and_longest_booking_and_what_the_shortest_costs(): void
    {
        [$a] = $this->timed(200, 30, 240);

        $o = $this->bookingOptionsOf($a)[0];

        $this->assertSame(['time', 'per_hour', 'per_30_minutes'], [$o['reservation_type'], $o['booking_model'], $o['price_unit']], 'The price is for a 30-minute block, and says so');
        $this->assertEquals([200, 30, 30, 240, 200], [$o['price'], $o['increment_minutes'], $o['min_minutes'], $o['max_minutes'], $o['min_minutes_price']]);
        $this->assertNull($o['adult_price']);
        $this->assertNull($o['child_price']);
    }

    public function test_a_longer_block_costs_the_price_of_the_block_for_its_shortest_booking(): void
    {
        [$a] = $this->timed(800, 120, 480);

        $o = $this->bookingOptionsOf($a)[0];

        $this->assertEquals([120, 120, 480, 800, 'per_120_minutes'], [$o['increment_minutes'], $o['min_minutes'], $o['max_minutes'], $o['min_minutes_price'], $o['price_unit']]);
    }

    public function test_an_option_in_hours_as_it_always_was_is_a_block_of_an_hour_with_its_own_minimum(): void
    {
        [$a] = $this->hourly(600);

        $o = $this->bookingOptionsOf($a)[0];

        $this->assertSame('per_hour', $o['price_unit']);
        $this->assertEquals([60, 120, 480, 1200], [$o['increment_minutes'], $o['min_minutes'], $o['max_minutes'], $o['min_minutes_price']], 'Two hours at least: 120 minutes, which is two blocks of an hour');
    }

    public function test_an_option_with_no_maximum_has_no_limit(): void
    {
        [$a] = $this->timed(200, 30, null);

        $this->assertNull($this->bookingOptionsOf($a)[0]['max_minutes']);
    }

    public function test_the_departures_list_the_windows_already_taken_and_say_whether_they_end_the_next_day(): void
    {
        [$a, , $p] = $this->timed();
        $this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated();

        $day = collect($this->ca($this->token, 'GET', "/activities/{$a->id}/availability?month=2026-11")->json('data.days'))->firstWhere('date', self::NEXT_SATURDAY);

        $this->assertSame([['from' => self::NEXT_SATURDAY.' 09:00', 'to' => self::NEXT_SATURDAY.' 10:00']], $day['slots'][0]['booked_windows']);
        $this->assertFalse($day['slots'][0]['ends_next_day']);
    }

    public function test_the_departures_of_a_booking_by_persons_are_as_they_were(): void
    {
        $day = collect($this->ca($this->token, 'GET', "/activities/{$this->a['activity']->id}/availability?month=2026-11")->json('data.days'))->firstWhere('date', self::NEXT_SATURDAY);

        $this->assertSame(['id', 'time', 'end_time', 'spots_left', 'is_available'], array_keys($day['slots'][0]));
    }

    // --- Booking by persons stays what it was ------------------------------------------------------------------------------

    public function test_a_booking_by_persons_is_priced_by_adults_and_children_and_says_so(): void
    {
        $this->a['option']->pricingRules()->create(['participant_type' => 'child', 'price' => 750]);

        $d = $this->summary(['activity_id' => $this->a['activity']->id, 'booking_type' => 'persons', 'date' => self::NEXT_SATURDAY, 'slot_id' => (int) $this->a['activity']->schedulePeriods()->value('id'),
            'participants' => ['adult' => 2, 'child' => 1]])->assertOk()->json('data');

        $this->assertSame(['persons', 'persons'], [$d['booking_type'], $d['reservation_type']]);
        $this->assertEquals([3750, 3], [$d['subtotal'], $d['guests']]);
        $this->assertArrayNotHasKey('minutes', $d['details']);
    }

    public function test_persons_and_a_window_cannot_be_asked_for_together(): void
    {
        [$a, , $p] = $this->timed();

        $this->summary($this->window($a, $p, '09:00', '10:00', ['booking_type' => 'persons']))->assertStatus(422)->assertJsonValidationErrors('booking_type');
        $this->summary(['activity_id' => $a->id, 'booking_type' => 'time_window', 'slot_id' => $p->id, 'date' => self::NEXT_SATURDAY])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->summary($this->window($a, $p, '09:00', '10:00', ['booking_type' => 'nonsense']))->assertStatus(422)->assertJsonValidationErrors('booking_type');
    }

    // --- Pricing: the number of blocks times the block price ---------------------------------------------------------------

    public function test_thirty_minutes_at_two_hundred_is_two_hundred_sixty_is_four_hundred_ninety_six_hundred_and_a_hundred_and_twenty_eight_hundred(): void
    {
        [$a, , $p] = $this->timed(200, 30);

        $this->assertEquals([200, 400, 600, 800], [$this->total($this->window($a, $p, '09:00', '09:30')), $this->total($this->window($a, $p, '09:00', '10:00')),
            $this->total($this->window($a, $p, '09:00', '10:30')), $this->total($this->window($a, $p, '09:00', '11:00'))]);
    }

    public function test_the_summary_says_how_it_is_made_of(): void
    {
        [$a, $o, $p] = $this->timed(200, 30);

        $d = $this->summary($this->window($a, $p, '09:30', '11:00'))->assertOk()->json('data');   // 90 minutes: three blocks

        $this->assertSame(['time_window', 'time'], [$d['booking_type'], $d['reservation_type']]);
        $this->assertEquals([600, 600], [$d['subtotal'], $d['total']]);
        $this->assertEquals([['duration_unit', '30 minutes', 3, 200, 600, 30]], array_map(fn ($l) => [$l['type'], $l['label'], $l['quantity'], $l['unit_price'], $l['total'], $l['unit_minutes']], $d['lines']));
        $this->assertSame(['from' => self::NEXT_SATURDAY.' 09:30', 'to' => self::NEXT_SATURDAY.' 11:00', 'minutes' => 90], array_intersect_key($d['details'], array_flip(['from', 'to', 'minutes'])));
        $this->assertSame([$p->id, self::NEXT_SATURDAY, '09:00', '13:00', $o->id], [$d['slot']['id'], $d['slot']['date'], $d['slot']['time'], $d['slot']['end_time'], $d['booking_option']['id']]);
    }

    public function test_a_block_of_any_size_and_price_is_what_the_provider_set(): void
    {
        [$a, , $p] = $this->timed(450, 45, 180);   // 45 minutes at 450

        $this->assertEquals([450, 900, 1350, 1800], [$this->total($this->window($a, $p, '09:00', '09:45')), $this->total($this->window($a, $p, '09:00', '10:30')),
            $this->total($this->window($a, $p, '09:00', '11:15')), $this->total($this->window($a, $p, '09:00', '12:00'))]);
    }

    public function test_the_price_is_not_prorated_by_the_minute(): void
    {
        [$a, , $p] = $this->timed(200, 30);

        // 40 minutes is not a number of blocks, so it is refused, and not "1.33 blocks".
        $this->summary($this->window($a, $p, '09:00', '09:40'))->assertStatus(422)->assertJsonPath('errors.to.0', 'A booking for this option is a whole number of 30-minute blocks (for example 30, 60 or 90 minutes).');
    }

    public function test_an_option_in_hours_is_priced_by_the_hour_and_a_block_is_an_hour(): void
    {
        [$a, , $p] = $this->hourly(600);

        $this->assertEquals([1200, 1800], [$this->total($this->window($a, $p, '09:00', '11:00')), $this->total($this->window($a, $p, '09:00', '12:00'))]);
        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertStatus(422)->assertJsonPath('errors.to.0', 'The shortest booking for this option is 120 minutes.');
        $this->summary($this->window($a, $p, '09:00', '11:30'))->assertStatus(422)->assertJsonValidationErrors('to');

        $d = $this->summary($this->window($a, $p, '09:00', '11:00'))->json('data');
        $this->assertSame(['hour', 'Hour', 2, 60], [$d['lines'][0]['type'], $d['lines'][0]['label'], $d['lines'][0]['quantity'], $d['lines'][0]['unit_minutes']]);
    }

    public function test_a_coupon_takes_its_part_off_a_window_like_any_booking(): void
    {
        [$a, , $p] = $this->timed(200, 30);
        Coupon::create(['provider_id' => $this->a['provider']->id, 'code' => 'TENOFF', 'type' => 'percent', 'value' => 10, 'is_active' => true, 'usage_limit' => 5]);

        $d = $this->summary($this->window($a, $p, '09:00', '11:00', ['coupon_code' => 'tenoff']))->assertOk()->json('data');

        $this->assertEquals([800, 80, 720], [$d['subtotal'], $d['discount'], $d['total']]);
        $this->assertTrue($d['coupon']['is_valid']);
    }

    // --- Increments, limits and what a window must be ------------------------------------------------------------------------

    public function test_the_length_must_be_a_whole_number_of_blocks_within_the_shortest_and_the_longest(): void
    {
        [$a, , $p] = $this->timed(200, 30, 120);

        $this->summary($this->window($a, $p, '09:00', '09:20'))->assertStatus(422)->assertJsonPath('errors.to.0', 'The shortest booking for this option is 30 minutes.');
        $this->summary($this->window($a, $p, '09:00', '09:45'))->assertStatus(422)->assertJsonValidationErrors('to');
        $this->summary($this->window($a, $p, '09:00', '11:30'))->assertStatus(422)->assertJsonPath('errors.to.0', 'The longest booking for this option is 120 minutes.');
        $this->summary($this->window($a, $p, '09:00', '09:30'))->assertOk();   // exactly the shortest
        $this->summary($this->window($a, $p, '09:00', '11:00'))->assertOk();   // exactly the longest
    }

    public function test_a_window_may_start_at_any_minute_as_long_as_it_is_whole_blocks_long(): void
    {
        [$a, , $p] = $this->timed(200, 30);

        $this->assertEquals(400, $this->total($this->window($a, $p, '09:10', '10:10')));
    }

    public function test_the_window_must_be_wholly_inside_the_operating_period(): void
    {
        [$a, , $p] = $this->timed();

        $this->summary($this->window($a, $p, '08:30', '09:30'))->assertStatus(422)->assertJsonPath('errors.from.0', 'The window must be inside the departure (09:00 to 13:00).');
        $this->summary($this->window($a, $p, '12:30', '13:30'))->assertStatus(422)->assertJsonValidationErrors('from');
        $this->summary($this->window($a, $p, '11:00', '10:00', ['to' => self::NEXT_SATURDAY.' 10:00']))->assertStatus(422)->assertJsonValidationErrors('to');   // the end before the start
        $this->summary($this->window($a, $p, '09:00', '09:30'))->assertOk();   // the very start
        $this->summary($this->window($a, $p, '12:30', '13:00'))->assertOk();   // up to the very end
    }

    public function test_the_departure_the_day_and_the_time_must_be_real(): void
    {
        [$a, , $p] = $this->timed();
        $other = $this->timed(200, 30, 240, '09:00', '13:00', 'Other')[2];

        $this->summary($this->window($a, $p, '09:00', '10:00', ['from' => '2026-11-22 09:00', 'to' => '2026-11-22 10:00']))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');   // a Sunday
        $this->summary($this->window($a, $other, '09:00', '10:00'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');   // another activity's period
        $this->summary($this->window($a, $p, '09:00', '10:00', ['slot_id' => 999999]))->assertStatus(409);
        $this->summary($this->window($a, $p, '09:00', '10:00', [], '2026-11-14'))->assertStatus(409);   // last Saturday: gone
        $this->summary(['activity_id' => $a->id, 'from' => self::NEXT_SATURDAY.' 09:00'])->assertStatus(422)->assertJsonValidationErrors(['to', 'slot_id']);
        $this->summary($this->window($a, $p, '09:00', '10:00', ['from' => 'tomorrow']))->assertStatus(422)->assertJsonValidationErrors('from');
    }

    public function test_a_time_that_has_already_started_is_gone_but_a_later_one_in_the_same_period_is_not(): void
    {
        [$a, , $p] = $this->timed();
        Carbon::setTestNow(self::NEXT_SATURDAY.' 08:00:00');   // 11:00 in Riyadh, in the middle of the period

        $this->summary($this->window($a, $p, '10:00', '11:30'))->assertStatus(409)->assertJsonPath('message', 'That time has already passed.');
        $this->summary($this->window($a, $p, '12:00', '13:00'))->assertOk();
    }

    public function test_a_closed_day_cannot_be_booked(): void
    {
        [$a, , $p] = $this->timed();
        $a->availabilityOverrides()->create(['date_from' => self::NEXT_SATURDAY, 'date_to' => self::NEXT_SATURDAY, 'is_unavailable' => true, 'status' => 'active']);

        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    }

    public function test_only_an_option_booked_by_time_takes_a_window(): void
    {
        $this->summary(['activity_id' => $this->a['activity']->id, 'slot_id' => (int) $this->a['activity']->schedulePeriods()->value('id'), 'from' => self::NEXT_SATURDAY.' 09:00', 'to' => self::NEXT_SATURDAY.' 11:00'])
            ->assertStatus(422)->assertJsonValidationErrors('booking_option_id');

        [$a, , $p] = $this->timed();
        $day = $a->bookingOptions()->create(['name' => 'By the day', 'slug' => 'by-the-day', 'booking_model' => 'per_day', 'is_active' => true, 'min_duration' => 1, 'duration_unit' => 'day']);
        $day->pricingRules()->create(['participant_type' => null, 'price' => 3000]);
        $this->summary($this->window($a, $p, '09:00', '10:00', ['booking_option_id' => $day->id]))->assertStatus(422)->assertJsonValidationErrors('booking_option_id');
    }

    public function test_the_option_booked_by_time_is_found_even_when_the_default_is_another(): void
    {
        [$a, $timed, $p] = $this->timed(200, 30);
        $a->bookingOptions()->where('id', '!=', $timed->id)->delete();
        $timed->update(['is_default' => false]);
        $trip = $a->bookingOptions()->create(['name' => 'Whole trip', 'slug' => 'whole-trip', 'booking_model' => 'per_trip', 'is_active' => true, 'is_default' => true]);
        $trip->pricingRules()->create(['participant_type' => null, 'price' => 9000]);

        $d = $this->summary($this->window($a, $p, '09:00', '10:00'))->assertOk()->json('data');

        $this->assertSame([$timed->id, 400], [$d['booking_option']['id'], (int) $d['total']]);
    }

    // --- Operating periods that cross midnight ------------------------------------------------------------------------------

    /** A night cruise: Fridays from 20:00 to 05:00 the next day (the first Friday after the pinned date is the 20th). */
    private function night(float $price = 200, int $block = 30, ?int $max = 480): array
    {
        [$a, $o, $p] = $this->timed($price, $block, $max, '20:00', '05:00');
        $p->update(['day_of_week' => 5]);

        return [$a, $o, $p->refresh()];
    }

    private const FRIDAY = '2026-11-20';

    public function test_a_period_from_eight_pm_to_five_am_is_one_valid_period(): void
    {
        [, , $p] = $this->night();

        $this->assertTrue($p->crosses_midnight);
        $this->assertSame(['20:00', '05:00'], [substr($p->start_time, 0, 5), substr($p->end_time, 0, 5)]);
    }

    public function test_the_availability_lists_it_on_the_day_it_starts_and_says_it_ends_the_next_day(): void
    {
        [$a, , $p] = $this->night();

        $day = collect($this->ca($this->token, 'GET', "/activities/{$a->id}/availability?month=2026-11")->json('data.days'))->firstWhere('date', self::FRIDAY);

        $this->assertSame([$p->id, '20:00', '05:00', true], [$day['slots'][0]['id'], $day['slots'][0]['time'], $day['slots'][0]['end_time'], $day['slots'][0]['ends_next_day']]);
        $this->assertSame([], collect($this->ca($this->token, 'GET', "/activities/{$a->id}/availability?month=2026-11")->json('data.days'))->firstWhere('date', '2026-11-21')['slots']);
    }

    public function test_windows_inside_the_evening_across_midnight_and_after_midnight_are_all_valid(): void
    {
        [$a, , $p] = $this->night(200, 30);
        $w = fn (string $from, string $to, ?string $toDay = null) => ['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => $from, 'to' => ($toDay ?? substr($from, 0, 10)).' '.$to];

        $this->assertEquals(400, $this->total($w('2026-11-20 21:00', '22:00')), 'In the evening');
        $this->assertEquals(600, $this->total($w('2026-11-20 23:30', '01:00', '2026-11-21')), 'Across midnight');
        $this->assertEquals(400, $this->total($w('2026-11-21 02:00', '03:00')), 'After midnight: still the Friday departure');
        $this->assertEquals(200, $this->total($w('2026-11-21 04:30', '05:00')), 'Right up to five o\'clock');
        $this->assertEquals(3200, $this->total($w('2026-11-20 20:00', '04:00', '2026-11-21')), 'Eight hours: sixteen blocks');
    }

    public function test_a_window_after_midnight_belongs_to_the_departure_that_started_the_evening_before(): void
    {
        [$a, , $p] = $this->night();

        $d = $this->summary(['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => '2026-11-21 02:00', 'to' => '2026-11-21 03:00'])->assertOk()->json('data');

        $this->assertSame('2026-11-20', $d['slot']['date'], 'The operating day is the Friday');
        // the date, when sent, must be that
        $this->summary(['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => '2026-11-21 02:00', 'to' => '2026-11-21 03:00', 'date' => '2026-11-20'])->assertOk();
        $this->summary(['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => '2026-11-21 02:00', 'to' => '2026-11-21 03:00', 'date' => '2026-11-21'])->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_a_window_must_be_wholly_inside_an_overnight_period_too(): void
    {
        [$a, , $p] = $this->night();

        $this->summary(['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => '2026-11-21 04:30', 'to' => '2026-11-21 05:30'])->assertStatus(422)->assertJsonPath('errors.from.0', 'The window must be inside the departure (20:00 to 05:00 the next day).');
        $this->summary(['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => '2026-11-20 19:30', 'to' => '2026-11-20 20:30'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->summary(['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => '2026-11-21 05:00', 'to' => '2026-11-21 06:00'])->assertStatus(422)->assertJsonValidationErrors('from');   // after the period has ended
        $this->summary(['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => '2026-11-21 21:00', 'to' => '2026-11-21 22:00'])->assertStatus(409);   // a Saturday evening: the period runs on Fridays
    }

    public function test_an_overnight_window_is_booked_and_kept_with_its_two_times_and_its_operating_day(): void
    {
        [$a, , $p] = $this->night(200, 30);

        $r = $this->book(['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => '2026-11-20 23:30', 'to' => '2026-11-21 01:00'])->assertCreated();
        $reservation = Reservation::findOrFail($r->json('data.booking_id'));

        $this->assertSame(['2026-11-20 20:30:00', '2026-11-20 22:00:00', 90, '2026-11-20'], [$reservation->starts_at->utc()->format('Y-m-d H:i:s'), $reservation->ends_at->utc()->format('Y-m-d H:i:s'),
            $reservation->duration_minutes, $reservation->reservation_date->toDateString()], 'UTC instants (Riyadh is three hours ahead), on the Friday departure');
        $this->assertEquals(600, $reservation->total);

        $d = $this->ca($this->token, 'GET', "/bookings/{$reservation->id}")->assertOk()->json('data');
        $this->assertSame(['time_window', '2026-11-20 23:30', '2026-11-21 01:00', 90], [$d['booking_type'], $d['from'], $d['to'], $d['duration_minutes']]);
    }

    public function test_windows_overlap_across_midnight_and_touching_ones_do_not(): void
    {
        [$a, , $p] = $this->night(200, 30);
        $w = fn (string $from, string $to, ?string $toDay = null) => ['activity_id' => $a->id, 'slot_id' => $p->id, 'from' => $from, 'to' => ($toDay ?? substr($from, 0, 10)).' '.$to];
        $this->book($w('2026-11-20 23:30', '00:30', '2026-11-21'))->assertCreated();

        $this->book($w('2026-11-21 00:00', '01:00'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');                     // overlaps the part after midnight
        $this->book($w('2026-11-20 23:00', '00:00', '2026-11-21'))->assertStatus(409);                                                   // overlaps the part before midnight
        $this->book($w('2026-11-20 23:45', '00:15', '2026-11-21'))->assertStatus(409);                                                   // wholly inside it, across midnight
        $this->book($w('2026-11-21 00:00', '00:30'))->assertStatus(409);                                                                 // wholly inside it
        $this->summary($w('2026-11-20 23:00', '00:00', '2026-11-21'))->assertStatus(409)->assertJsonPath('message', 'This time is already booked (23:30 to 00:30).');
        $this->book($w('2026-11-20 23:00', '23:30'))->assertCreated();                                                                   // touches at 23:30
        $this->book($w('2026-11-21 00:30', '01:30'))->assertCreated();                                                                   // touches at 00:30
        $this->assertSame(3, Reservation::count());
    }

    // --- Overlapping bookings are not allowed ----------------------------------------------------------------------------------

    public function test_a_booking_keeps_its_window_and_a_second_one_cannot_overlap_it(): void
    {
        [$a, , $p] = $this->timed(200, 30);
        $r = $this->book($this->window($a, $p, '09:00', '11:00'))->assertCreated();
        $reservation = Reservation::findOrFail($r->json('data.booking_id'));

        $this->assertSame([self::NEXT_SATURDAY.' 06:00:00', self::NEXT_SATURDAY.' 08:00:00', 120, 2], [$reservation->starts_at->utc()->format('Y-m-d H:i:s'), $reservation->ends_at->utc()->format('Y-m-d H:i:s'),
            $reservation->duration_minutes, $reservation->duration], 'Kept as UTC instants: 09:00 in Riyadh is 06:00');
        $this->assertEquals(800, $reservation->total);

        foreach ([['10:00', '12:00'], ['09:00', '11:00'], ['09:30', '10:30'], ['10:30', '11:30'], ['08:30', '09:30']] as [$from, $to]) {
            if ($from === '08:30') {
                continue;   // outside the period: refused for that first
            }
            $this->book($this->window($a, $p, $from, $to))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
            $this->summary($this->window($a, $p, $from, $to))->assertStatus(409)->assertJsonPath('message', 'This time is already booked (09:00 to 11:00).');
        }
        $this->assertSame(1, Reservation::count(), 'None of the refused ones was made');
    }

    public function test_windows_that_only_touch_are_both_fine(): void
    {
        [$a, , $p] = $this->timed();

        $this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated();
        $this->book($this->window($a, $p, '10:00', '11:00'))->assertCreated();

        $this->assertSame(2, Reservation::whereNotNull('starts_at')->count());
        $this->summary($this->window($a, $p, '09:30', '10:30'))->assertStatus(409);
    }

    public function test_the_overlap_is_checked_across_the_whole_option_not_just_one_period(): void
    {
        [$a, $o, $p] = $this->timed();
        $second = $a->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '12:00', 'end_time' => '16:00']);   // runs into the first from 12:00
        $this->book($this->window($a, $p, '12:00', '13:00'))->assertCreated();

        // The same hour, asked for through the other period, is the same hour.
        $this->book($this->window($a, $second, '12:00', '13:00'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->book($this->window($a, $second, '13:00', '14:00'))->assertCreated();
        $day = collect($this->ca($this->token, 'GET', "/activities/{$a->id}/availability?month=2026-11")->json('data.days'))->firstWhere('date', self::NEXT_SATURDAY);
        $this->assertCount(2, $day['slots'][1]['booked_windows'] ?? $day['slots'][0]['booked_windows']);
    }

    public function test_another_option_or_another_activity_does_not_clash(): void
    {
        [$a, , $p] = $this->timed();
        [$b, , $pb] = $this->timed(200, 30, 240, '09:00', '13:00', 'Other boat');
        $this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated();

        $this->summary($this->window($b, $pb, '09:00', '10:00'))->assertOk();
    }

    public function test_a_booking_by_the_hour_made_without_a_window_holds_the_period_from_its_start(): void
    {
        [$a, $o, $p] = $this->hourly(600);
        [, $customer] = $this->makeCustomer();
        // Made the way it was before windows: three hours, no window.
        app(ReservationService::class)->create($customer, $a, $o, $p, \Illuminate\Support\Carbon::parse(self::NEXT_SATURDAY), null, null, 3)->update(['status' => 'confirmed']);

        $this->summary($this->window($a, $p, '09:00', '11:00'))->assertStatus(409)->assertJsonPath('message', 'This time is already booked (09:00 to 12:00).');
        $this->summary($this->window($a, $p, '11:00', '13:00'))->assertStatus(409);   // 11:00 to 12:00 is still held
        $this->summary($this->window($a, $p, '12:00', '13:00'))->assertStatus(422);   // free, but an hour is shorter than the option's two
    }

    // --- Unpaid bookings hold their time; cancelled ones give it back -----------------------------------------------------------

    public function test_an_unpaid_booking_holds_its_time_until_the_hold_runs_out(): void
    {
        [$a, , $p] = $this->timed();
        $first = Reservation::findOrFail($this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated()->json('data.booking_id'));

        $this->assertSame('pending', $first->status->value);
        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertStatus(409);

        Carbon::setTestNow(Carbon::now()->addMinutes((int) config('reservations.hold_minutes') - 1));
        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertStatus(409);   // still held a minute before it runs out

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertOk();            // the hold ran out: the time is back
        $this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated();
    }

    public function test_a_paid_booking_holds_its_time_for_good(): void
    {
        [$a, , $p] = $this->timed();
        $first = Reservation::findOrFail($this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated()->json('data.booking_id'));
        $first->update(['status' => 'confirmed', 'confirmed_at' => now(), 'hold_expires_at' => null]);

        Carbon::setTestNow(Carbon::now()->addDays(2)->setTime(7, 0));   // long after any hold
        $this->summary($this->window($a, $p, '09:00', '10:00', [], '2026-11-21'))->assertStatus(409);
    }

    public function test_cancelling_a_booking_gives_its_time_back_and_so_does_a_booking_that_expired(): void
    {
        [$a, , $p] = $this->timed();
        $first = Reservation::findOrFail($this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated()->json('data.booking_id'));

        $this->ca($this->token, 'POST', "/bookings/{$first->id}/cancel", ['reason' => 'Plans changed'])->assertOk();
        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertOk();

        $second = Reservation::findOrFail($this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated()->json('data.booking_id'));
        foreach (['cancelled', 'expired'] as $status) {
            $second->update(['status' => $status]);
            $this->summary($this->window($a, $p, '09:00', '10:00'))->assertOk();
        }
        $second->update(['status' => 'confirmed']);
        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertStatus(409);
    }

    public function test_a_confirmed_booking_that_is_cancelled_gives_its_time_back_too(): void
    {
        [$a, , $p] = $this->timed();
        $first = Reservation::findOrFail($this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated()->json('data.booking_id'));
        $first->update(['status' => 'confirmed', 'confirmed_at' => now(), 'hold_expires_at' => null]);

        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertStatus(409);
        $first->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->summary($this->window($a, $p, '09:00', '10:00'))->assertOk();
    }

    // --- How it is kept safe from two at once (the database test of it is tests/Concurrency/check_booking_concurrency.py, on MySQL) ---------------

    public function test_a_booking_by_time_locks_the_option_and_not_the_departure_and_the_lock_is_made_once(): void
    {
        [$a, $o, $p] = $this->timed();

        $this->book($this->window($a, $p, '09:00', '10:00'))->assertCreated();
        $this->book($this->window($a, $p, '10:00', '11:00'))->assertCreated();

        $locks = \DB::table('reservation_capacity_locks')->where('activity_booking_option_id', $o->id)->get();
        $this->assertCount(1, $locks, 'One lock row for the option, however many bookings');
        $this->assertSame([0, '1970-01-01'], [(int) $locks[0]->schedule_period_id, substr((string) $locks[0]->reservation_date, 0, 10)]);
    }

    public function test_a_booking_by_persons_keeps_its_own_lock_for_its_departure_and_day_as_it_always_did(): void
    {
        $this->book(['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1])->assertCreated();

        $lock = \DB::table('reservation_capacity_locks')->where('activity_booking_option_id', $this->a['option']->id)->first();

        $this->assertSame([(int) $this->a['activity']->schedulePeriods()->value('id'), self::NEXT_SATURDAY], [(int) $lock->schedule_period_id, substr((string) $lock->reservation_date, 0, 10)]);
    }

    // --- The booking read back ----------------------------------------------------------------------------------------------

    public function test_the_booking_says_what_it_is_for_and_its_card_is_priced_per_block(): void
    {
        [$a, , $p] = $this->timed(200, 30);
        $id = $this->book($this->window($a, $p, '09:30', '11:00'))->assertCreated()->json('data.booking_id');

        $d = $this->ca($this->token, 'GET', "/bookings/{$id}")->assertOk()->json('data');

        $this->assertSame(['time_window', self::NEXT_SATURDAY.' 09:30', self::NEXT_SATURDAY.' 11:00', 90], [$d['booking_type'], $d['from'], $d['to'], $d['duration_minutes']]);
        $this->assertEquals(600, $d['total']);
        $this->assertSame(['per_30_minutes', 200], [$d['activity']['price_unit'], (int) $d['activity']['price']], 'The card shows the price of ONE block');
    }

    public function test_a_booking_by_persons_has_no_window_in_its_answer(): void
    {
        $id = $this->book(['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 2])->assertCreated()->json('data.booking_id');

        $d = $this->ca($this->token, 'GET', "/bookings/{$id}")->assertOk()->json('data');

        $this->assertSame(['persons', null, null, null], [$d['booking_type'], $d['from'], $d['to'], $d['duration_minutes']]);
    }

    public function test_sending_the_same_booking_again_with_the_same_key_gives_the_same_booking_even_though_its_time_is_now_taken(): void
    {
        [$a, , $p] = $this->timed();

        $first = $this->book($this->window($a, $p, '09:00', '10:00'), 'one-key')->assertCreated()->json('data.booking_id');
        $again = $this->book($this->window($a, $p, '09:00', '10:00'), 'one-key')->assertSuccessful()->json('data.booking_id');

        $this->assertSame($first, $again, 'A double tap is not a conflict with itself');
        $this->assertSame(1, Reservation::count());
    }

    public function test_the_card_of_a_listing_says_the_block_too(): void
    {
        [$a] = $this->timed(200, 30);

        $row = collect($this->ca($this->token, 'GET', '/activities?per_page=50')->assertOk()->json('data'))->firstWhere('id', $a->id);

        $this->assertSame(['per_30_minutes', 'time'], [$row['price_unit'], $row['reservation_type']]);
    }
}
