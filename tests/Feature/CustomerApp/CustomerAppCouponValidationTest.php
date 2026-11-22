<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * POST /customer-app/activities/{id}/coupons/validate: a coupon checked for an activity before it is used. The activity is per person at 1,500 an adult and runs on Saturdays at
 * 09:00; today is Sunday 15 November 2026. A coupon that cannot be used is a normal answer (`is_valid` false, and why), not an error.
 */
class CustomerAppCouponValidationTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private const KEYS = ['code', 'is_valid', 'message', 'type', 'value', 'max_discount', 'min_order_amount', 'currency', 'subtotal', 'discount', 'service_fee', 'vat', 'total'];

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

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create($attrs + ['provider_id' => $this->a['provider']->id, 'code' => 'SAVE10', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
    }

    private function validate(array $body, ?int $activityId = null, ?string $token = null)
    {
        return $this->ca($token ?? $this->token, 'POST', '/activities/'.($activityId ?? $this->a['activity']->id).'/coupons/validate', $body);
    }

    // --- A coupon that can be used ---------------------------------------------------------------------------------------------

    public function test_a_coupon_that_can_be_used_says_what_it_is_and_has_no_discount_until_it_knows_the_price(): void
    {
        $this->coupon(['min_order_amount' => 500, 'max_discount' => 200]);

        $r = $this->validate(['coupon_code' => ' save10 '])->assertOk();

        $this->assertSame(['status', 'message', 'data'], array_keys($r->json()));
        $d = $r->json('data');
        $this->assertSame(self::KEYS, array_keys($d));
        $this->assertSame(['SAVE10', true, '10% discount applied', 'percent', config('platform.currency')], [$d['code'], $d['is_valid'], $d['message'], $d['type'], $d['currency']]);
        $this->assertEquals([10, 200, 500], [$d['value'], $d['max_discount'], $d['min_order_amount']]);
        $this->assertSame([null, null, null, null, null], [$d['subtotal'], $d['discount'], $d['service_fee'], $d['vat'], $d['total']]);
    }

    public function test_given_an_amount_it_works_out_what_the_coupon_takes_off(): void
    {
        $this->coupon();

        $d = $this->validate(['coupon_code' => 'SAVE10', 'amount' => 1000])->assertOk()->json('data');

        $this->assertEquals([true, 1000, 100, 900], [$d['is_valid'], $d['subtotal'], $d['discount'], $d['total']]);
    }

    public function test_given_the_booking_it_prices_it_as_the_summary_does_and_takes_the_coupon_off_that(): void
    {
        $this->a['option']->pricingRules()->create(['participant_type' => 'child', 'price' => 750]);
        $this->coupon();
        $booking = ['date' => self::NEXT_SATURDAY, 'participants' => ['adult' => 2, 'child' => 1]];

        $byTime = $this->validate($booking + ['coupon_code' => 'SAVE10', 'time' => '09:00'])->assertOk()->json('data');
        $byId = $this->validate($booking + ['code' => 'SAVE10', 'slot_id' => $this->a['period']])->assertOk()->json('data');
        $summary = $this->ca($this->token, 'POST', '/bookings/summary', $booking + ['activity_id' => $this->a['activity']->id, 'time' => '09:00', 'coupon_code' => 'SAVE10'])->json('data');

        $this->assertEquals([3750, 375, 3375], [$byTime['subtotal'], $byTime['discount'], $byTime['total']]);
        $this->assertEquals($byTime, $byId, '`code` is accepted for `coupon_code`, and the departure may be named by its id');
        $this->assertEquals([$summary['subtotal'], $summary['discount'], $summary['total']], [$byTime['subtotal'], $byTime['discount'], $byTime['total']], 'The same answer as the summary');
    }

    public function test_the_booking_may_be_by_time_or_units_too(): void
    {
        $hourly = $this->moreActivity($this->a, 'Charter', 100);
        $option = $hourly->bookingOptions()->first();
        $option->update(['booking_model' => 'per_hour', 'is_default' => true, 'min_duration' => 1, 'max_duration' => 8]);
        $option->pricingRules()->delete();
        $option->pricingRules()->create(['participant_type' => null, 'price' => 700]);
        $this->coupon(['code' => 'HALF', 'value' => 50]);

        $d = $this->validate(['coupon_code' => 'HALF', 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'duration' => 4], $hourly->id)->assertOk()->json('data');

        $this->assertEquals([2800, 1400, 1400], [$d['subtotal'], $d['discount'], $d['total']]);
    }

    public function test_a_fixed_coupon_and_a_percent_coupon_with_a_limit_take_off_what_they_say(): void
    {
        $this->coupon(['code' => 'FIFTY', 'type' => 'fixed', 'value' => 50]);
        $this->coupon(['code' => 'BIG', 'value' => 50, 'max_discount' => 100]);

        $fixed = $this->validate(['coupon_code' => 'FIFTY', 'amount' => 1000])->json('data');
        $capped = $this->validate(['coupon_code' => 'BIG', 'amount' => 1000])->json('data');
        $rules = $this->validate(['coupon_code' => 'FIFTY'])->json('data');

        $this->assertEquals(['fixed', 50, 950], [$fixed['type'], $fixed['discount'], $fixed['total']]);
        $this->assertEquals([100, 900], [$capped['discount'], $capped['total']], 'Fifty per cent would be 500: the limit is 100');
        $this->assertEquals([true, 'fixed', 50, null], [$rules['is_valid'], $rules['type'], $rules['value'], $rules['discount']], 'Without a price there is nothing to take off yet');
    }

    public function test_checking_a_coupon_uses_none_of_it_up(): void
    {
        $coupon = $this->coupon(['usage_limit' => 1]);

        foreach (range(1, 3) as $i) {
            $this->assertTrue($this->validate(['coupon_code' => 'SAVE10', 'amount' => 1000])->json('data.is_valid'));
        }

        $this->assertSame(0, CouponRedemption::count());
        $this->assertTrue($this->validate(['coupon_code' => 'SAVE10'])->json('data.is_valid'), 'Still there for the booking');
        $this->assertSame(1, $coupon->usage_limit);
    }

    // --- A coupon that cannot be used --------------------------------------------------------------------------------------------

    public function test_a_coupon_that_cannot_be_used_is_a_normal_answer_that_says_why(): void
    {
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->coupon(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        $this->coupon(['code' => 'BIGONLY', 'min_order_amount' => 2000]);
        $this->coupon(['code' => 'ONCE', 'usage_limit' => 1]);
        $onlyOther = $this->coupon(['code' => 'OTHERS']);
        $onlyOther->activities()->attach($this->moreActivity($this->a, 'Another', 100)->id);

        $reservation = Reservation::forceCreate($this->bookingRow());
        CouponRedemption::create(['coupon_id' => Coupon::where('code', 'ONCE')->value('id'), 'reservation_id' => $reservation->id, 'customer_id' => $reservation->customer_id, 'code' => 'ONCE', 'discount_amount' => 10]);

        $cases = [
            ['NOPE', 'This coupon code is not valid.', []],
            ['OFF', 'This coupon code is not valid.', []],
            ['OLD', 'This coupon has expired.', []],
            ['OTHERS', 'This coupon cannot be used for this activity.', []],
            ['ONCE', 'This coupon has been used up.', []],
            ['BIGONLY', 'This coupon needs a booking of at least 2,000.00 '.config('platform.currency').'.', ['amount' => 1500]],
        ];

        foreach ($cases as [$code, $message, $extra]) {
            $r = $this->validate(['coupon_code' => $code] + $extra)->assertOk();

            $this->assertSame(self::KEYS, array_keys($r->json('data')), $code);
            $this->assertSame([$code, false, $message], [$r->json('data.code'), $r->json('data.is_valid'), $r->json('data.message')], $code);
            $this->assertSame([null, null, null, null], [$r->json('data.type'), $r->json('data.value'), $r->json('data.discount'), $r->json('data.total')], $code);
        }
    }

    public function test_an_unknown_code_and_a_switched_off_one_and_another_providers_are_answered_alike(): void
    {
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $other = $this->approvedProvider();
        Coupon::create(['provider_id' => $other['provider']->id, 'code' => 'THEIRS', 'type' => 'percent', 'value' => 10, 'is_active' => true]);

        $messages = array_map(fn ($code) => $this->validate(['coupon_code' => $code])->json('data.message'), ['NOPE', 'OFF', 'THEIRS']);

        $this->assertSame(['This coupon code is not valid.'], array_values(array_unique($messages)), 'Nothing tells which codes exist, or whose they are');
    }

    // --- What is refused as a request ----------------------------------------------------------------------------------------------

    public function test_a_bad_request_is_a_validation_error_by_field(): void
    {
        foreach ([
            [[], 'coupon_code'], [['coupon_code' => ''], 'coupon_code'], [['coupon_code' => str_repeat('A', 41)], 'coupon_code'],
            [['coupon_code' => 'X', 'amount' => -1], 'amount'], [['coupon_code' => 'X', 'amount' => 'lots'], 'amount'],
            [['coupon_code' => 'X', 'date' => self::NEXT_SATURDAY], 'time'], [['coupon_code' => 'X', 'time' => '09:00'], 'date'], [['coupon_code' => 'X', 'slot_id' => 5], 'date'],
            [['coupon_code' => 'X', 'date' => '2026-11-14', 'time' => '09:00'], 'date'], [['coupon_code' => 'X', 'date' => '15 Nov'], 'date'],
            [['coupon_code' => 'X', 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'participants' => ['senior' => 1]], 'participants.senior'],
        ] as [$body, $field]) {
            $this->validate($body)->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }

    public function test_a_booking_that_cannot_be_priced_is_refused_as_the_summary_refuses_it(): void
    {
        $this->coupon();

        $this->validate(['coupon_code' => 'SAVE10', 'date' => self::NEXT_SATURDAY, 'time' => '10:00', 'guests' => 1])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->validate(['coupon_code' => 'SAVE10', 'date' => self::NEXT_SATURDAY, 'time' => '09:00'])->assertStatus(422)->assertJsonValidationErrors('guests');
    }

    // --- Who ---------------------------------------------------------------------------------------------------------------

    public function test_it_is_for_signed_in_customers_and_for_activities_that_can_be_booked(): void
    {
        $this->coupon();
        $this->ca(null, 'POST', '/activities/'.$this->a['activity']->id.'/coupons/validate', ['coupon_code' => 'SAVE10'])->assertUnauthorized();
        $this->validate(['coupon_code' => 'SAVE10'], null, $this->a['token'])->assertForbidden();

        $this->validate(['coupon_code' => 'SAVE10'], 99999)->assertNotFound();
        $draft = $this->moreActivity($this->a, 'Draft', 100);
        $draft->update(['status' => 'draft']);
        $this->validate(['coupon_code' => 'SAVE10'], $draft->id)->assertNotFound();
        $this->validate(['coupon_code' => 'SAVE10'], 0)->assertNotFound();
    }

    public function test_it_is_limited_to_twenty_a_minute_so_it_cannot_be_used_to_guess_codes(): void
    {
        foreach (range(1, 20) as $i) {
            $this->validate(['coupon_code' => "GUESS{$i}"])->assertOk();
        }

        $this->validate(['coupon_code' => 'GUESS21'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        // Another customer has their own allowance.
        [, , $other] = $this->signedInCustomer();
        $this->validate(['coupon_code' => 'GUESS1'], null, $other)->assertOk();
    }

    /** A booking row of this provider, for a coupon to have been used on. @return array<string, mixed> */
    private function bookingRow(): array
    {
        [, $customer] = $this->makeCustomer();

        return ['uuid' => (string) Str::uuid(), 'activity_id' => $this->a['activity']->id, 'activity_booking_option_id' => $this->a['option']->id, 'customer_id' => $customer->id,
            'schedule_period_id' => $this->a['period'], 'reservation_date' => self::NEXT_SATURDAY, 'participants' => ['adult' => 1], 'status' => 'confirmed', 'pricing_model' => 'per_person',
            'currency' => 'SAR', 'subtotal' => 1500, 'discount' => 10, 'service_fee' => 0, 'tax' => 0, 'total' => 1490, 'pricing_breakdown' => []];
    }
}
