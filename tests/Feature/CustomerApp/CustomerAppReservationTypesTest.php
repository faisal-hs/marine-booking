<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * Pricing and booking by RESERVATION TYPE: what a booking is made of is said as the price option needs it, persons (`guests`, or `participants` by kind), units (`quantity`), time
 * (`duration`) or fixed (one price), for a departure named by its `time` or its `slot_id`, and for a price option chosen with `booking_option_id`. The scenario activity is per
 * person at 1,500 an adult and runs on Saturdays at 09:00; today is Sunday 15 November 2026.
 */
class CustomerAppReservationTypesTest extends TestCase
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

    /** An activity of its own, priced by `$model` at `$price`, whose one option is the default. @return array{0: Activity, 1: ActivityBookingOption} */
    private function activityOf(string $model, float $price, array $optionAttrs = [], string $title = 'Boat'): array
    {
        $activity = $this->moreActivity($this->a, $title, 100);
        $option = $activity->bookingOptions()->first();
        $option->update(['booking_model' => $model, 'is_default' => true] + $optionAttrs);
        $option->pricingRules()->delete();
        $option->pricingRules()->create(['participant_type' => null, 'price' => $price]);

        return [$activity->refresh(), $option->refresh()];
    }

    private function summary(array $over = [], ?Activity $activity = null)
    {
        $activity ??= $this->a['activity'];

        return $this->ca($this->token, 'POST', '/bookings/summary', $over + ['activity_id' => $activity->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00']);
    }

    private function book(array $over = [], ?Activity $activity = null, ?string $key = null)
    {
        $activity ??= $this->a['activity'];
        $body = $over + ['activity_id' => $activity->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'contact_name' => 'Ahmed Al-Harbi', 'contact_phone' => '+966501234567',
            'payment_method' => 'card', 'simulate' => 'async'];

        return $this->ca($this->token, 'POST', '/bookings', $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function slotOf(Activity $activity): int
    {
        return (int) $activity->schedulePeriods()->value('id');
    }

    // --- Persons -----------------------------------------------------------------------------------------------------

    public function test_guests_by_kind_are_each_priced_at_their_own_price(): void
    {
        $this->a['option']->pricingRules()->create(['participant_type' => 'child', 'price' => 750]);

        $d = $this->summary(['participants' => ['adult' => 2, 'child' => 1]])->assertOk()->json('data');

        $this->assertSame('persons', $d['reservation_type']);
        $this->assertSame(['id' => $this->a['option']->id, 'name' => 'Standard', 'reservation_type' => 'persons', 'booking_model' => 'per_person'], $d['booking_option']);
        $this->assertEquals([['adult', 'Adult', 2, 1500, 3000], ['child', 'Child', 1, 750, 750]], array_map(fn ($l) => [$l['type'], $l['label'], $l['quantity'], $l['unit_price'], $l['total']], $d['lines']));
        $this->assertEquals([3750, 3, 1250], [$d['subtotal'], $d['guests'], $d['unit_price']]);
        $this->assertSame(['guests' => 3, 'participants' => ['adult' => 2, 'child' => 1], 'quantity' => null, 'duration' => null], $d['details']);
    }

    public function test_guests_alone_are_adults_as_it_always_was(): void
    {
        $d = $this->summary(['guests' => 2])->assertOk()->json('data');

        $this->assertEquals([3000, 2, 1500], [$d['subtotal'], $d['guests'], $d['unit_price']]);
        $this->assertSame([['adult', 2]], array_map(fn ($l) => [$l['type'], $l['quantity']], $d['lines']));
    }

    public function test_persons_need_guests_or_participants_and_a_kind_must_be_one_that_has_a_price(): void
    {
        $this->summary()->assertStatus(422)->assertJsonValidationErrors('guests');
        $this->summary(['participants' => ['adult' => 0]])->assertStatus(422)->assertJsonValidationErrors('guests');
        $this->summary(['participants' => ['senior' => 1]])->assertStatus(422)->assertJsonValidationErrors('participants.senior');
        $this->summary(['participants' => ['adult' => 1, 'infant' => 1]])->assertStatus(422)->assertJsonValidationErrors('participants.infant');   // no infant price
        $this->summary(['participants' => ['adult' => 'two']])->assertStatus(422)->assertJsonValidationErrors('participants.adult');
    }

    public function test_the_places_of_the_option_are_kept_and_a_full_departure_is_a_conflict(): void
    {
        $this->a['option']->update(['max_participants' => 5]);

        $this->summary(['guests' => 6])->assertStatus(422)->assertJsonValidationErrors('guests');
        $this->book(['guests' => 5])->assertCreated();
        $this->summary(['guests' => 1])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->book(['guests' => 1])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    }

    // --- The departure ---------------------------------------------------------------------------------------------------

    public function test_a_departure_can_be_named_by_its_id_as_well_as_by_its_time(): void
    {
        $byTime = $this->summary(['guests' => 2])->assertOk()->json('data');
        $byId = $this->summary(['guests' => 2, 'time' => null, 'slot_id' => $this->a['period']])->assertOk()->json('data');

        $this->assertSame($byTime['subtotal'], $byId['subtotal']);
        $this->assertSame(['id' => $this->a['period'], 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'end_time' => '13:00'], $byId['slot']);
    }

    public function test_a_wrong_departure_is_not_available_whichever_way_it_is_named(): void
    {
        $other = $this->moreActivity($this->a, 'Another', 100);

        $this->summary(['guests' => 1, 'time' => null, 'slot_id' => $this->slotOf($other)])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');   // another activity's
        $this->summary(['guests' => 1, 'time' => null, 'slot_id' => 999999])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->summary(['guests' => 1, 'time' => null, 'slot_id' => $this->a['period'], 'date' => '2026-11-22'])->assertStatus(409);   // a Sunday: the period is Saturday's
        $this->summary(['guests' => 1, 'time' => '10:00'])->assertStatus(409);
        $this->summary(['guests' => 1, 'time' => null])->assertStatus(422)->assertJsonValidationErrors(['time', 'slot_id']);
        $this->book(['guests' => 1, 'time' => null, 'slot_id' => 999999])->assertStatus(409);
    }

    // --- The price option -----------------------------------------------------------------------------------------------

    public function test_a_price_option_can_be_chosen_and_the_default_is_used_when_none_is(): void
    {
        $vip = $this->a['activity']->bookingOptions()->create(['name' => 'VIP', 'slug' => 'vip-'.uniqid(), 'booking_model' => 'per_person', 'sort_order' => 2]);
        $vip->pricingRules()->create(['participant_type' => 'adult', 'price' => 2500]);

        $default = $this->summary(['guests' => 2])->assertOk()->json('data');
        $chosen = $this->summary(['guests' => 2, 'booking_option_id' => $vip->id])->assertOk()->json('data');

        $this->assertEquals([3000, 'Standard'], [$default['subtotal'], $default['booking_option']['name']]);
        $this->assertEquals([5000, 'VIP', $vip->id], [$chosen['subtotal'], $chosen['booking_option']['name'], $chosen['booking_option']['id']]);

        $id = $this->book(['guests' => 2, 'booking_option_id' => $vip->id])->assertCreated()->json('data.booking_id');
        $this->assertSame($vip->id, Reservation::findOrFail($id)->activity_booking_option_id);
        $this->assertEquals(5000, Reservation::findOrFail($id)->total);
    }

    public function test_an_option_that_is_off_or_belongs_to_another_activity_is_refused_by_name(): void
    {
        $off = $this->a['activity']->bookingOptions()->create(['name' => 'Old', 'slug' => 'old-'.uniqid(), 'booking_model' => 'per_person', 'is_active' => false]);
        $off->pricingRules()->create(['participant_type' => 'adult', 'price' => 99]);
        [, $foreign] = $this->activityOf('per_hour', 700, ['min_duration' => 1]);

        foreach ([$off->id, $foreign->id, 999999] as $id) {
            $this->summary(['guests' => 1, 'booking_option_id' => $id])->assertStatus(422)->assertJsonValidationErrors('booking_option_id');
            $this->book(['guests' => 1, 'booking_option_id' => $id])->assertStatus(422)->assertJsonValidationErrors('booking_option_id');
        }
        $this->assertSame(0, Reservation::count());
    }

    // --- Units -----------------------------------------------------------------------------------------------------------

    public function test_a_booking_by_units_is_priced_by_the_quantity_and_the_stock_is_kept(): void
    {
        [$activity] = $this->activityOf('per_unit', 300, ['min_quantity' => 1, 'max_quantity' => 4], 'Kayaks');

        $d = $this->summary(['quantity' => 3], $activity)->assertOk()->json('data');
        $this->assertSame('units', $d['reservation_type']);
        $this->assertEquals([900, 300], [$d['subtotal'], $d['unit_price']]);
        $this->assertEquals([['unit', 'Unit', 3, 300, 900]], array_map(fn ($l) => [$l['type'], $l['label'], $l['quantity'], $l['unit_price'], $l['total']], $d['lines']));
        $this->assertNull($d['guests']);
        $this->assertSame(3, $d['details']['quantity']);

        $this->summary([], $activity)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->summary(['quantity' => 5], $activity)->assertStatus(422)->assertJsonValidationErrors('quantity');   // more than the option has at all

        $this->book(['quantity' => 3], $activity)->assertCreated();
        $left = $this->summary(['quantity' => 2], $activity)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->assertStringContainsString('Only 1 unit(s) are left', $left->json('message'));
        $this->summary(['quantity' => 1], $activity)->assertOk();
        $this->book(['quantity' => 2], $activity)->assertStatus(409);
    }

    // --- Time ------------------------------------------------------------------------------------------------------------

    public function test_a_booking_by_the_hour_or_the_day_is_priced_by_the_duration_within_the_options_limits(): void
    {
        [$hourly] = $this->activityOf('per_hour', 700, ['min_duration' => 2, 'max_duration' => 6, 'duration_unit' => 'hour'], 'Charter');
        [$daily] = $this->activityOf('per_day', 2000, ['min_duration' => 1, 'max_duration' => 3], 'Yacht');

        // By the hour it is a WINDOW: from and to (the length is the difference between them).
        $window = fn (string $to) => ['slot_id' => $hourly->schedulePeriods()->first()->id, 'from' => self::NEXT_SATURDAY.' 09:00', 'to' => self::NEXT_SATURDAY.' '.$to];

        $h = $this->summary($window('12:00'), $hourly)->assertOk()->json('data');
        $this->assertSame('time', $h['reservation_type']);
        $this->assertEquals([2100, 700, [['hour', 'Hour', 3, 700, 2100]]], [$h['subtotal'], $h['unit_price'], array_map(fn ($l) => [$l['type'], $l['label'], $l['quantity'], $l['unit_price'], $l['total']], $h['lines'])]);
        $this->assertSame([180, null], [$h['details']['minutes'], $h['details']['duration']]);

        $d = $this->summary(['duration' => 2], $daily)->assertOk()->json('data');
        $this->assertEquals([4000, 'day'], [$d['subtotal'], $d['lines'][0]['type']]);

        $this->summary([], $hourly)->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);                    // by the hour, from and to are required
        $this->summary(['duration' => 3], $hourly)->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);    // and a duration in hours is no longer a way to ask
        $this->summary($window('10:00'), $hourly)->assertStatus(422)->assertJsonValidationErrors('to');              // one hour is under the minimum of 2
        $this->summary(['duration' => 4], $daily)->assertStatus(422)->assertJsonValidationErrors('duration');          // by the day it is still a duration, within 1 to 3
    }

    // --- Fixed -----------------------------------------------------------------------------------------------------------

    public function test_a_booking_at_one_price_costs_that_whatever_the_number_of_guests(): void
    {
        [$activity] = $this->activityOf('per_trip', 5000, ['max_participants' => 6], 'Private Trip');

        $none = $this->summary([], $activity)->assertOk()->json('data');
        $four = $this->summary(['guests' => 4], $activity)->assertOk()->json('data');

        $this->assertSame('fixed', $none['reservation_type']);
        $this->assertEquals([5000, 5000, null], [$none['subtotal'], $none['unit_price'], $none['guests']]);
        $this->assertEquals([5000, 4], [$four['subtotal'], $four['guests']]);
        $this->assertEquals([['trip', 'Trip', 1, 5000, 5000]], array_map(fn ($l) => [$l['type'], $l['label'], $l['quantity'], $l['unit_price'], $l['total']], $four['lines']));
        $this->summary(['guests' => 7], $activity)->assertStatus(422)->assertJsonValidationErrors('guests');
    }

    public function test_what_does_not_belong_to_the_type_is_left_out_and_is_not_an_error(): void
    {
        [$fixed] = $this->activityOf('fixed', 4000, [], 'Flight');

        $persons = $this->summary(['guests' => 2, 'quantity' => 9, 'duration' => 9])->assertOk()->json('data');
        $flat = $this->summary(['quantity' => 9, 'duration' => 9], $fixed)->assertOk()->json('data');

        $this->assertEquals(3000, $persons['subtotal']);
        $this->assertSame([null, null], [$persons['details']['quantity'], $persons['details']['duration']]);
        $this->assertEquals(4000, $flat['subtotal']);
    }

    // --- A coupon, whatever the type ---------------------------------------------------------------------------------------

    public function test_a_coupon_takes_its_share_off_a_booking_by_time_and_is_used_up_by_the_booking(): void
    {
        [$activity] = $this->activityOf('per_hour', 700, ['min_duration' => 1, 'max_duration' => 8], 'Charter');
        Coupon::create(['provider_id' => $this->a['provider']->id, 'code' => 'HALF', 'type' => 'percent', 'value' => 50, 'is_active' => true, 'usage_limit' => 1]);

        $window = fn (string $from, string $to) => ['slot_id' => $activity->schedulePeriods()->first()->id, 'from' => self::NEXT_SATURDAY." {$from}", 'to' => self::NEXT_SATURDAY." {$to}"];

        $s = $this->summary($window('09:00', '11:00') + ['coupon_code' => 'half'], $activity)->assertOk()->json('data');
        $this->assertEquals([1400, 700, 700], [$s['subtotal'], $s['discount'], $s['total']]);
        $this->assertTrue($s['coupon']['is_valid']);

        $id = $this->book($window('09:00', '11:00') + ['coupon_code' => 'HALF'], $activity)->assertCreated()->json('data.booking_id');
        $this->assertEquals(700, Reservation::findOrFail($id)->total);
        $this->assertSame(1, CouponRedemption::count());
        $this->assertFalse($this->summary($window('11:00', '13:00') + ['coupon_code' => 'HALF'], $activity)->assertOk()->json('data.coupon.is_valid'), 'Used up (and this window is free)');
    }

    // --- The booking ------------------------------------------------------------------------------------------------------

    public function test_a_booking_keeps_what_it_is_made_of_and_shows_it_in_every_answer(): void
    {
        $this->a['option']->pricingRules()->create(['participant_type' => 'child', 'price' => 750]);
        [$unit] = $this->activityOf('per_unit', 300, ['min_quantity' => 1, 'max_quantity' => 4], 'Kayaks');
        [$hour] = $this->activityOf('per_hour', 700, ['min_duration' => 1, 'max_duration' => 8], 'Charter');

        $persons = $this->book(['participants' => ['adult' => 2, 'child' => 1]])->assertCreated()->json('data.booking_id');
        $units = $this->book(['quantity' => 2], $unit)->assertCreated()->json('data.booking_id');
        $time = $this->book(['slot_id' => $hour->schedulePeriods()->first()->id, 'from' => self::NEXT_SATURDAY.' 09:00', 'to' => self::NEXT_SATURDAY.' 12:00'], $hour)->assertCreated()->json('data.booking_id');

        $r = Reservation::findOrFail($persons);
        $this->assertSame(['adult' => 2, 'child' => 1], $r->participants);
        $this->assertEquals([3750, 'per_person'], [$r->total, $r->pricing_model]);
        $this->assertEquals([600, 2, null], [Reservation::findOrFail($units)->total, Reservation::findOrFail($units)->quantity, Reservation::findOrFail($units)->duration]);
        $this->assertEquals([2100, 3], [Reservation::findOrFail($time)->total, Reservation::findOrFail($time)->duration]);

        foreach ([[$persons, 'persons', 3, null, null, 1250, 'per_person'], [$units, 'units', 0, 2, null, 300, 'per_unit'], [$time, 'time', 0, null, 3, 700, 'per_hour']] as [$id, $type, $guests, $quantity, $duration, $unitPrice, $unitName]) {
            $detail = $this->ca($this->token, 'GET', "/bookings/{$id}")->assertOk()->json('data');

            $this->assertSame([$type, $guests, $quantity, $duration], [$detail['reservation_type'], $detail['guests'], $detail['quantity'], $detail['duration']], $type);
            $this->assertEquals([$unitPrice, $unitName, $type], [$detail['activity']['price'], $detail['activity']['price_unit'], $detail['activity']['reservation_type']], "{$type}: the card shows the price of one guest, unit or hour");
        }

        $list = collect($this->ca($this->token, 'GET', '/bookings')->assertOk()->json('data.bookings'))->keyBy('id');
        $this->assertSame(['units', 2], [$list[$units]['reservation_type'], $list[$units]['quantity']]);
    }

    public function test_the_same_key_gives_the_same_booking_and_the_booking_checks_what_the_summary_checks(): void
    {
        [$unit] = $this->activityOf('per_unit', 300, ['min_quantity' => 1, 'max_quantity' => 4], 'Kayaks');

        $first = $this->book(['quantity' => 2], $unit, 'same-key')->assertCreated()->json('data.booking_id');
        $again = $this->book(['quantity' => 2], $unit, 'same-key')->assertOk()->json('data.booking_id');

        $this->assertSame($first, $again);
        $this->assertSame(1, Reservation::count());
        $this->book([], $unit)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->book(['quantity' => 9], $unit)->assertStatus(422)->assertJsonValidationErrors('quantity');
    }
}
