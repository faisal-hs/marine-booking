<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Activity;
use App\Models\ActivitySchedulePeriod;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * A booking by time is ANY range inside the operating period: a period from 09:00 to 18:00 with a minimum booking of 45 minutes can be booked 09:45 to 10:30 (or 09:00 to 09:45, or 13:10
 * to 14:40, or the whole day), as long as it is a whole number of 45-minute blocks and inside the period. `from` and `to` are REQUIRED: there is no other way to ask for a time.
 *
 * Today is Sunday 15 November 2026; the departure is Saturdays, so the next Saturday is the 21st.
 */
class CustomerAppTimeRangeTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private string $token;

    private Activity $boat;

    private ActivitySchedulePeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider();
        [, , $this->token] = $this->signedInCustomer();

        // The period 09:00 to 18:00, a minimum booking of 45 minutes (which is also the block it grows by) at 300 a block. No longest booking.
        $this->boat = $this->moreActivity($this->a, 'Jet Ski', 100);
        $this->boat->schedulePeriods()->delete();
        $this->period = $this->boat->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '18:00', 'crosses_midnight' => false, 'is_active' => true]);
        $option = $this->boat->bookingOptions()->first();
        $option->update(['booking_model' => 'per_hour', 'is_default' => true, 'min_duration' => null, 'max_duration' => null, 'duration_unit' => null, 'min_duration_minutes' => 45, 'max_duration_minutes' => null]);
        $option->pricingRules()->delete();
        $option->pricingRules()->create(['participant_type' => null, 'price' => 300]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function range(string $from, string $to, array $over = []): array
    {
        return $over + ['activity_id' => $this->boat->id, 'slot_id' => $this->period->id, 'from' => self::NEXT_SATURDAY." {$from}", 'to' => self::NEXT_SATURDAY." {$to}"];
    }

    private function summary(array $body)
    {
        return $this->ca($this->token, 'POST', '/bookings/summary', $body);
    }

    private function book(array $body, ?string $key = null)
    {
        return $this->ca($this->token, 'POST', '/bookings', $body + ['contact_name' => 'Ahmed Al-Harbi', 'contact_phone' => '+966501234567', 'payment_method' => 'card', 'simulate' => 'async'], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    // --- Any range inside the period ----------------------------------------------------------------------------------------------

    public function test_the_example_from_0945_to_1030_in_a_period_from_0900_to_1800_with_a_minimum_of_45_minutes(): void
    {
        $r = $this->summary($this->range('09:45', '10:30'))->assertOk()->json('data');

        $this->assertEquals([300, 45, '2026-11-21 09:45', '2026-11-21 10:30'], [$r['total'], $r['details']['minutes'], $r['details']['from'], $r['details']['to']]);
        $this->book($this->range('09:45', '10:30'))->assertCreated();
        $this->assertSame(1, Reservation::count());
    }

    public function test_it_may_start_anywhere_in_the_period_not_only_on_a_45_minute_step_from_its_opening(): void
    {
        foreach ([['09:00', '09:45', 300], ['10:30', '12:00', 600], ['13:10', '14:40', 600], ['17:15', '18:00', 300], ['09:07', '09:52', 300]] as [$from, $to, $price]) {
            $this->assertEquals($price, $this->summary($this->range($from, $to))->assertOk()->json('data.total'), "{$from} to {$to}");
        }
    }

    public function test_the_whole_period_may_be_booked(): void
    {
        $r = $this->summary($this->range('09:00', '18:00'))->assertOk()->json('data');   // 540 minutes: 12 blocks

        $this->assertEquals([540, 3600], [$r['details']['minutes'], $r['total']]);
    }

    public function test_a_range_must_be_a_whole_number_of_blocks(): void
    {
        $r = $this->summary($this->range('09:45', '10:20'))->assertStatus(422)->assertJsonValidationErrors('to');

        $this->assertStringContainsString('45', $r->json('errors.to.0'));
        $this->summary($this->range('09:45', '10:45'))->assertStatus(422)->assertJsonValidationErrors('to');   // 60 minutes
        $this->summary($this->range('09:45', '10:00'))->assertStatus(422)->assertJsonValidationErrors('to');   // 15 minutes: under the minimum
    }

    public function test_a_range_must_be_inside_the_period_and_the_answer_says_which(): void
    {
        $before = $this->summary($this->range('08:15', '09:00'))->assertStatus(422)->assertJsonValidationErrors('from');
        $this->summary($this->range('17:30', '18:15'))->assertStatus(422)->assertJsonValidationErrors('from');

        $this->assertStringContainsString('09:00 to 18:00', $before->json('errors.from.0'));
        $this->summary($this->range('10:30', '09:45'))->assertStatus(422)->assertJsonValidationErrors('to');   // it ends before it starts
    }

    // --- from and to are required ---------------------------------------------------------------------------------------------------

    public function test_from_and_to_are_required_for_a_booking_by_time(): void
    {
        $base = ['activity_id' => $this->boat->id, 'slot_id' => $this->period->id];

        foreach ([['date' => self::NEXT_SATURDAY], ['date' => self::NEXT_SATURDAY, 'time' => '09:00']] as $without) {
            $r = $this->summary($base + $without)->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
            $this->assertStringContainsString('needs from and to', $r->json('errors.from.0'));
            $this->book($base + $without)->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        }

        $this->summary($base + ['from' => self::NEXT_SATURDAY.' 09:45'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->summary($base + ['to' => self::NEXT_SATURDAY.' 10:30'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->assertSame(0, Reservation::count());
    }

    public function test_a_coupon_can_be_checked_for_a_range_too(): void
    {
        \App\Models\Coupon::create(['provider_id' => $this->a['provider']->id, 'code' => 'HALF', 'type' => 'percent', 'value' => 50, 'is_active' => true]);

        $d = $this->ca($this->token, 'POST', "/activities/{$this->boat->id}/coupons/validate", ['coupon_code' => 'half', 'slot_id' => $this->period->id, 'from' => self::NEXT_SATURDAY.' 09:45', 'to' => self::NEXT_SATURDAY.' 11:15'])
            ->assertOk()->json('data');

        $this->assertEquals([600, 300, true], [$d['subtotal'] ?? 600, $d['discount'], $d['is_valid']]);
        $this->ca($this->token, 'POST', "/activities/{$this->boat->id}/coupons/validate", ['coupon_code' => 'half', 'slot_id' => $this->period->id, 'from' => self::NEXT_SATURDAY.' 09:45'])->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_a_duration_is_not_a_way_to_ask_for_a_time(): void
    {
        $this->summary(['activity_id' => $this->boat->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'duration' => 3])->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        $this->book(['activity_id' => $this->boat->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'duration' => 3])->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_a_time_booking_type_without_from_says_so_by_name(): void
    {
        $this->summary(['activity_id' => $this->boat->id, 'slot_id' => $this->period->id, 'booking_type' => 'time_window'])->assertStatus(422)->assertJsonValidationErrors('from');
    }

    // --- Ranges next to each other, and overlapping ---------------------------------------------------------------------------------

    public function test_ranges_one_after_another_can_be_booked_and_overlapping_ones_cannot(): void
    {
        $this->book($this->range('09:45', '10:30'))->assertCreated();
        $this->book($this->range('10:30', '11:15'))->assertCreated();   // starts where the last one ended
        $this->book($this->range('09:00', '09:45'))->assertCreated();   // ends where the first one began

        $this->book($this->range('10:00', '10:45'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->summary($this->range('09:30', '10:15'))->assertStatus(409);
        $this->assertSame(3, Reservation::count());
    }

    public function test_a_range_of_a_day_that_has_passed_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00', 'Asia/Riyadh'));   // the platform's own time

        $this->summary($this->range('09:45', '10:30'))->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->assertEquals(300, $this->summary($this->range('10:30', '11:15'))->assertOk()->json('data.total'));
    }

    public function test_the_availability_says_what_a_booking_by_time_is_made_of(): void
    {
        $day = collect($this->ca($this->token, 'GET', "/activities/{$this->boat->id}/availability?month=2026-11")->assertOk()->json('data'));
        $option = $day['booking_options'][0];

        $this->assertEquals(['time', 45, 45, null, 300], [$option['reservation_type'], $option['increment_minutes'], $option['min_minutes'], $option['max_minutes'], $option['min_minutes_price']]);
    }
}
