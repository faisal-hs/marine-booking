<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * Choosing a departure and seeing the price. The activity runs on Saturdays at 09:00 (Riyadh); today is Sunday 15 November 2026 and costs 1,500 an adult.
 */
class CustomerAppAvailabilityAndSummaryTest extends TestCase
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

    private function days(array $query = [], ?int $id = null): array
    {
        return collect($this->ca(null, 'GET', '/activities/'.($id ?? $this->a['activity']->id).'/availability', $query)->assertOk()->json('data.days'))->keyBy('date')->all();
    }

    private function summary(array $over = [], ?string $token = null)
    {
        return $this->ca($token ?? $this->token, 'POST', '/bookings/summary', $over + ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1]);
    }

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create($attrs + ['provider_id' => $this->a['provider']->id, 'code' => 'SUMMER20', 'type' => 'percent', 'value' => 20, 'is_active' => true]);
    }

    private function fill(int $guests, string $status = 'confirmed', ?array $extra = null): int
    {
        [, $customer] = $this->makeCustomer();

        return $this->makeBooking($this->a, $customer, self::NEXT_SATURDAY, $status, 1500, ['participants' => json_encode(['adult' => $guests])] + ($extra ?? []));
    }

    // --- Availability -------------------------------------------------------------------------------------------------

    public function test_a_window_lists_every_day_with_its_departures_in_the_specifications_shape(): void
    {
        $this->a['activity']->update(['max_participants' => 8]);

        $data = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/availability", ['from' => '2026-11-15', 'to' => '2026-11-22'])->assertOk()->json('data');

        $this->assertSame(['timezone', 'max_guests', 'days'], array_keys($data));
        $this->assertSame(['Asia/Riyadh', 8, 8], [$data['timezone'], $data['max_guests'], count($data['days'])]);
        $saturday = collect($data['days'])->firstWhere('date', self::NEXT_SATURDAY);
        $this->assertSame(['date', 'is_available', 'slots'], array_keys($saturday));
        $this->assertSame([true, [['time' => '09:00', 'spots_left' => 8]]], [$saturday['is_available'], $saturday['slots']]);
        $monday = collect($data['days'])->firstWhere('date', '2026-11-16');
        $this->assertSame([false, []], [$monday['is_available'], $monday['slots']]);
    }

    public function test_without_dates_it_is_the_next_fourteen_days_from_today(): void
    {
        $days = array_keys($this->days());

        $this->assertSame(['2026-11-15', '2026-11-28'], [$days[0], end($days)]);
        $this->assertCount(14, $days);
    }

    public function test_places_left_count_confirmed_bookings_and_held_ones_but_not_cancelled_or_expired(): void
    {
        $this->a['activity']->update(['max_participants' => 10]);
        $this->fill(2, 'confirmed');
        $this->fill(1, 'pending', ['hold_expires_at' => now()->addMinutes(10)->toDateTimeString()]);
        $this->fill(4, 'pending', ['hold_expires_at' => now()->subMinute()->toDateTimeString()]);   // a hold that ran out
        $this->fill(3, 'cancelled');
        $this->fill(3, 'expired');

        $this->assertSame(7, $this->days()[self::NEXT_SATURDAY]['slots'][0]['spots_left']);
    }

    public function test_the_capacity_is_the_options_else_the_activitys_else_there_is_no_limit(): void
    {
        $this->a['activity']->update(['max_participants' => 10]);
        $this->a['option']->update(['max_participants' => 4]);
        $this->assertSame(4, $this->days()[self::NEXT_SATURDAY]['slots'][0]['spots_left']);

        $this->a['option']->update(['max_participants' => null]);
        $this->assertSame(10, $this->days()[self::NEXT_SATURDAY]['slots'][0]['spots_left']);

        $this->a['activity']->update(['max_participants' => null]);
        $day = $this->days()[self::NEXT_SATURDAY];
        $this->assertNull($day['slots'][0]['spots_left']);
        $this->assertTrue($day['is_available'], 'No limit means there is always room');
    }

    public function test_a_full_departure_is_listed_with_nothing_left_and_the_day_is_not_available(): void
    {
        $this->a['activity']->update(['max_participants' => 2]);
        $this->fill(2);

        $day = $this->days()[self::NEXT_SATURDAY];

        $this->assertSame([['time' => '09:00', 'spots_left' => 0]], $day['slots']);
        $this->assertFalse($day['is_available']);
    }

    public function test_a_day_the_provider_closed_has_no_departures(): void
    {
        $this->a['activity']->availabilityOverrides()->create(['date_from' => self::NEXT_SATURDAY, 'date_to' => self::NEXT_SATURDAY, 'is_unavailable' => true]);

        $this->assertSame([false, []], [$this->days()[self::NEXT_SATURDAY]['is_available'], $this->days()[self::NEXT_SATURDAY]['slots']]);
    }

    public function test_a_departure_that_has_already_left_is_not_listed_but_a_later_one_the_same_day_is(): void
    {
        $this->a['activity']->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '16:00', 'end_time' => '18:00']);
        Carbon::setTestNow('2026-11-21 08:00:00');   // 11:00 in Riyadh: the 09:00 left, the 16:00 has not

        $this->assertSame([['time' => '16:00', 'spots_left' => null]], $this->days(['from' => '2026-11-21', 'to' => '2026-11-21'])['2026-11-21']['slots'] ?: []);
    }

    public function test_departures_are_listed_in_time_order(): void
    {
        $this->a['activity']->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '07:00', 'end_time' => '08:00']);
        $this->a['activity']->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '16:00', 'end_time' => '18:00']);

        $this->assertSame(['07:00', '09:00', '16:00'], array_column($this->days()[self::NEXT_SATURDAY]['slots'], 'time'));
    }

    public function test_a_start_in_the_past_is_clamped_to_today_and_a_bad_window_is_refused(): void
    {
        $this->assertSame('2026-11-15', array_key_first($this->days(['from' => '2026-10-20', 'to' => '2026-11-16'])));
        $this->assertSame([], $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/availability", ['from' => '2026-01-01', 'to' => '2026-01-05'])->json('data.days'));

        foreach ([['from' => 'tomorrow'], ['to' => '16/11/2026'], ['from' => '2026-11-20', 'to' => '2026-11-10'], ['from' => '2026-11-15', 'to' => '2027-03-01']] as $bad) {
            $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/availability", $bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->days(['from' => '2026-11-15', 'to' => '2027-01-15']);   // 62 days: the longest
    }

    // --- Summary ----------------------------------------------------------------------------------------------------

    public function test_the_specifications_own_example_comes_out_to_the_riyal(): void
    {
        config(['customer_app.service_fee_percent' => 3, 'customer_app.vat_percent' => 15]);
        $this->a['option']->pricingRules()->update(['price' => 450]);
        $this->coupon();

        $r = $this->summary(['guests' => 2, 'coupon_code' => 'SUMMER20'])->assertOk();

        $this->assertSame(['currency', 'unit_price', 'guests', 'subtotal', 'discount', 'service_fee', 'vat', 'total', 'coupon'], array_keys($r->json('data')));
        $this->assertEqualsWithDelta([450, 2, 900, 180, 27, 112.05, 859.05], [$r->json('data.unit_price'), $r->json('data.guests'), $r->json('data.subtotal'), $r->json('data.discount'), $r->json('data.service_fee'), $r->json('data.vat'), $r->json('data.total')], 0.001);
        $this->assertSame(['code' => 'SUMMER20', 'is_valid' => true, 'message' => '20% discount applied'], $r->json('data.coupon'));
        $this->assertSame('SAR', $r->json('data.currency'));
    }

    public function test_the_service_fee_is_on_the_price_before_the_coupon_and_vat_on_what_is_left_plus_the_fee(): void
    {
        config(['customer_app.service_fee_percent' => 10, 'customer_app.vat_percent' => 5]);
        $this->coupon(['value' => 50]);

        $d = $this->summary(['coupon_code' => 'summer20'])->json('data');

        // price 1500, coupon 750, fee 150 (10% of 1500), VAT 45 (5% of 1500 - 750 + 150 = 900), total 1500 - 750 + 150 + 45
        $this->assertEqualsWithDelta([1500, 750, 150, 45, 945], [$d['subtotal'], $d['discount'], $d['service_fee'], $d['vat'], $d['total']], 0.001);
    }

    public function test_with_no_fee_and_no_vat_configured_the_total_is_the_price_less_the_coupon(): void
    {
        $d = $this->summary()->json('data');

        $this->assertEqualsWithDelta([1500, 0, 0, 0, 1500], [$d['subtotal'], $d['discount'], $d['service_fee'], $d['vat'], $d['total']], 0.001);
        $this->assertNull($d['coupon']);
    }

    public function test_a_coupon_that_cannot_be_used_is_reported_and_the_summary_is_worked_out_without_it(): void
    {
        $this->coupon(['code' => 'EXPIRED', 'expires_at' => now()->subDay()]);
        $this->coupon(['code' => 'BIGONLY', 'min_order_amount' => 5000]);
        $other = $this->approvedProvider();
        Coupon::create(['provider_id' => $other['provider']->id, 'code' => 'THEIRS', 'type' => 'percent', 'value' => 10, 'is_active' => true]);

        $cases = ['EXPIRED' => 'has expired', 'BIGONLY' => 'at least 5,000.00', 'THEIRS' => 'not valid', 'NOSUCH' => 'not valid'];

        foreach ($cases as $code => $why) {
            $d = $this->summary(['coupon_code' => $code])->assertOk()->json('data');
            $this->assertFalse($d['coupon']['is_valid'], $code);
            $this->assertStringContainsString($why, $d['coupon']['message'], $code);
            $this->assertSame([0, 1500], [$d['discount'], $d['total']], 'Worked out without the coupon');
        }
    }

    public function test_a_fixed_coupon_says_how_much_it_takes_off_and_the_message_follows_the_language(): void
    {
        $this->coupon(['code' => 'OFF100', 'type' => 'fixed', 'value' => 100]);

        $this->assertSame('100 SAR discount applied', $this->summary(['coupon_code' => 'OFF100'])->json('data.coupon.message'));
        $this->assertSame('تم تطبيق خصم 100 SAR', $this->ca($this->token, 'POST', '/bookings/summary', ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1, 'coupon_code' => 'OFF100'], ['Accept-Language' => 'ar'])->json('data.coupon.message'));
    }

    public function test_however_big_the_coupon_the_total_never_falls_below_the_minimum(): void
    {
        $this->coupon(['code' => 'FREE', 'value' => 100]);

        $d = $this->summary(['coupon_code' => 'FREE'])->json('data');

        $this->assertEqualsWithDelta([1499, 1], [$d['discount'], $d['total']], 0.001);
    }

    public function test_asking_for_a_summary_uses_up_nothing(): void
    {
        $this->coupon(['usage_limit' => 1]);
        $this->a['activity']->update(['max_participants' => 1]);

        foreach (range(1, 3) as $i) {
            $this->summary(['coupon_code' => 'SUMMER20'])->assertOk()->assertJsonPath('data.coupon.is_valid', true);
        }

        $this->assertSame([0, 0], [Reservation::count(), CouponRedemption::count()]);
        $this->assertSame(1, $this->days()[self::NEXT_SATURDAY]['slots'][0]['spots_left']);
    }

    public function test_a_departure_that_does_not_exist_or_has_left_is_one_conflict(): void
    {
        $wrongTime = $this->summary(['time' => '10:30'])->assertStatus(409);
        $this->assertSame('SLOT_UNAVAILABLE', $wrongTime->json('code'));
        $this->summary(['date' => '2026-11-22'])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');   // a Sunday
        $this->a['activity']->availabilityOverrides()->create(['date_from' => self::NEXT_SATURDAY, 'date_to' => self::NEXT_SATURDAY, 'is_unavailable' => true]);
        $this->summary()->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->a['activity']->availabilityOverrides()->delete();

        // The same day, once its 09:00 departure (06:00 UTC) has left: 08:00 UTC is 11:00 in Riyadh.
        Carbon::setTestNow('2026-11-21 08:00:00');
        $this->summary(['date' => '2026-11-21'])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    }

    public function test_guests_beyond_what_the_departure_can_take_are_refused_in_the_right_way(): void
    {
        $this->a['activity']->update(['max_participants' => 4]);
        $this->fill(3);

        $this->summary(['guests' => 5])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('guests');
        $left = $this->summary(['guests' => 2])->assertStatus(409);
        $this->assertSame(['SLOT_UNAVAILABLE', 'Only 1 place(s) are left on this departure.'], [$left->json('code'), $left->json('message')]);
        $this->summary(['guests' => 1])->assertOk();

        $this->fill(1);
        $this->assertSame('This departure is full.', $this->summary(['guests' => 1])->json('message'));
    }

    public function test_a_minimum_group_size_is_respected(): void
    {
        $this->a['option']->update(['min_participants' => 2]);

        $this->summary(['guests' => 1])->assertStatus(422)->assertJsonValidationErrors('guests');
        $this->summary(['guests' => 2])->assertOk();
    }

    public function test_bad_input_is_a_validation_error_and_unknown_activities_are_not_found(): void
    {
        foreach ([['guests' => 0], ['guests' => 101], ['guests' => 'two'], ['date' => 'next saturday'], ['date' => '2026-11-14'], ['date' => '2028-01-01'], ['time' => '9am'], ['coupon_code' => str_repeat('x', 41)]] as $bad) {
            $this->summary($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->ca($this->token, 'POST', '/bookings/summary', [])->assertStatus(422)->assertJsonValidationErrors(['activity_id', 'date', 'time', 'guests']);
        $this->summary(['activity_id' => 99999])->assertNotFound();
        $draft = $this->moreActivity($this->a, 'Draft', 100);
        $draft->update(['status' => 'draft']);
        $this->summary(['activity_id' => $draft->id])->assertNotFound();
    }

    public function test_an_activity_that_is_not_bookable_in_the_app_answers_in_the_specifications_way(): void
    {
        $this->a['option']->update(['booking_model' => 'per_unit']);

        $this->summary()->assertNotFound();   // not visible: it has no per-person option to book
    }

    public function test_the_summary_is_for_signed_in_customers_and_limited_to_thirty_a_minute(): void
    {
        $this->ca(null, 'POST', '/bookings/summary', [])->assertUnauthorized();

        foreach (range(1, 30) as $i) {
            $this->summary()->assertOk();
        }
        $this->summary()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
