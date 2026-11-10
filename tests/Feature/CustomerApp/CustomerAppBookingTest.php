<?php

namespace Tests\Feature\CustomerApp;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\CouponService;
use App\Services\Payments\UnavailablePaymentGateway;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * Booking and paying: what is stored, what the customer is told, and that a retry, a full departure or a bad coupon never leaves a half-made booking.
 * The activity costs 1,500 for an adult on Saturdays at 09:00; today is Sunday 15 November 2026.
 */
class CustomerAppBookingTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private $customer;

    private $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider(['contact_phone' => '+966551112233']);
        [$this->user, $this->customer, $this->token] = $this->signedInCustomer(['name' => 'Ahmed Al-Harbi']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function book(array $over = [], ?string $key = null, ?string $token = null, array $headers = [])
    {
        $body = $over + ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1, 'contact_name' => 'Ahmed Al-Harbi',
            'contact_phone' => '+966501234567', 'contact_email' => 'ahmed@gmail.com', 'payment_method' => 'card'];

        return $this->ca($token ?? $this->token, 'POST', '/bookings', $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()] + $headers);
    }

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create($attrs + ['provider_id' => $this->a['provider']->id, 'code' => 'SUMMER20', 'type' => 'percent', 'value' => 20, 'is_active' => true]);
    }

    // --- Booking ----------------------------------------------------------------------------------------------------

    public function test_a_booking_that_is_paid_at_once_is_confirmed_and_told_in_the_specifications_shape(): void
    {
        $r = $this->book()->assertCreated();

        $this->assertSame('Booking created', $r->json('message'));
        $this->assertSame(['booking_id', 'reference', 'status', 'total', 'payment_id', 'payment_status', 'redirect_url'], array_keys($r->json('data')));
        $this->assertSame(['BH-261121-0001', 'upcoming', 1500, 'succeeded', null], [$r->json('data.reference'), $r->json('data.status'), $r->json('data.total'), $r->json('data.payment_status'), $r->json('data.redirect_url')]);
        $this->assertStringStartsWith('PAY-', $r->json('data.payment_id'));
    }

    public function test_everything_about_the_booking_is_stored_as_the_server_worked_it_out(): void
    {
        $this->book(['guests' => 2, 'total' => 1, 'subtotal' => 1, 'discount' => 5000], null, null, ['X-Booking-Source' => 'category'])->assertCreated();

        $res = Reservation::firstOrFail();
        $this->assertSame([$this->customer->id, $this->a['activity']->id, 'confirmed', 'card', 'category', 'Ahmed Al-Harbi', '+966501234567', 'ahmed@gmail.com'],
            [$res->customer_id, $res->activity_id, $res->status->value, $res->payment_method, $res->source, $res->contact_name, $res->contact_phone, $res->contact_email]);
        $this->assertSame(['adult' => 2], $res->participants);
        $this->assertEquals([3000, 0, 0, 0, 3000], [$res->subtotal, $res->discount, $res->service_fee, $res->tax, $res->total], 'What the app sent as a total is ignored');
        $this->assertEquals(3000, Payment::firstOrFail()->amount, 'The customer is charged exactly the total');
        $this->assertSame($this->a['option']->id, $res->activity_booking_option_id);
        $this->assertNotNull($res->schedule_period_id);
    }

    public function test_the_service_fee_and_vat_are_stored_and_charged(): void
    {
        config(['customer_app.service_fee_percent' => 3, 'customer_app.vat_percent' => 15]);

        $r = $this->book()->assertCreated();

        $res = Reservation::firstOrFail();
        $this->assertEqualsWithDelta([1500, 45, 231.75, 1776.75], [$res->subtotal, $res->service_fee, $res->tax, $res->total], 0.001);
        $this->assertEqualsWithDelta(1776.75, $r->json('data.total'), 0.001);
        $this->assertEqualsWithDelta(1776.75, Payment::firstOrFail()->amount, 0.001);
        $this->assertEqualsWithDelta(1500, $res->providerAmount(), 0.001, 'The provider is owed the price, not the fee or the VAT');
    }

    public function test_a_payment_that_is_still_in_progress_leaves_the_booking_waiting_and_the_places_held(): void
    {
        $this->a['activity']->update(['max_participants' => 3]);

        $r = $this->book(['guests' => 2, 'simulate' => 'async'])->assertCreated();

        $this->assertSame(['pending_payment', 'pending'], [$r->json('data.status'), $r->json('data.payment_status')]);
        $this->assertStringStartsWith('https://mock-gateway.test/checkout/', $r->json('data.redirect_url'));
        $res = Reservation::firstOrFail();
        $this->assertSame('pending', $res->status->value);
        $this->assertTrue($res->hold_expires_at->isFuture());
        $this->assertSame(1, $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/availability", ['from' => self::NEXT_SATURDAY, 'to' => self::NEXT_SATURDAY])->json('data.days.0.slots.0.spots_left'), 'Held, so nobody else can take them');
    }

    public function test_a_declined_payment_does_not_confirm_the_booking(): void
    {
        $r = $this->book(['simulate' => 'sync_failure'])->assertCreated();

        $this->assertSame(['pending_payment', 'failed'], [$r->json('data.status'), $r->json('data.payment_status')]);
        $this->assertSame('pending', Reservation::firstOrFail()->status->value);
    }

    public function test_a_booking_is_confirmed_only_by_a_trusted_payment_never_by_the_app(): void
    {
        $this->book(['simulate' => 'async', 'payment_status' => 'succeeded', 'status' => 'confirmed', 'paid' => true])->assertCreated();

        $this->assertSame('pending', Reservation::firstOrFail()->status->value);
    }

    // --- Idempotency ------------------------------------------------------------------------------------------------

    public function test_the_idempotency_key_is_required(): void
    {
        $body = ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1, 'contact_name' => 'Ahmed', 'contact_phone' => '+966501234567', 'payment_method' => 'card'];

        foreach ([[], ['Idempotency-Key' => ''], ['Idempotency-Key' => '   '], ['Idempotency-Key' => str_repeat('k', 81)]] as $headers) {
            $this->ca($this->token, 'POST', '/bookings', $body, $headers)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        }
        $this->assertSame(0, Reservation::count());
    }

    public function test_sending_the_same_key_again_returns_the_same_booking_and_books_and_charges_nothing_more(): void
    {
        $first = $this->book([], 'tap-1')->assertCreated();
        $again = $this->book([], 'tap-1')->assertOk();
        $thrice = $this->book([], 'tap-1')->assertOk();

        $this->assertSame($first->json('data.booking_id'), $again->json('data.booking_id'));
        $this->assertSame($first->json('data.payment_id'), $thrice->json('data.payment_id'));
        $this->assertSame([1, 1], [Reservation::count(), Payment::count()]);
    }

    public function test_a_new_key_is_a_new_booking_and_each_customer_has_their_own_keys(): void
    {
        [, , $other] = $this->signedInCustomer();

        $one = $this->book([], 'same-key')->json('data.booking_id');
        $two = $this->book([], 'another-key')->json('data.booking_id');
        $three = $this->book([], 'same-key', $other)->assertCreated()->json('data.booking_id');

        $this->assertCount(3, array_unique([$one, $two, $three]));
    }

    public function test_a_retry_with_the_same_key_even_after_the_payment_went_through_is_still_the_same_booking(): void
    {
        $first = $this->book(['simulate' => 'async'], 'tap-2')->json('data');
        Payment::firstOrFail()->update(['status' => 'succeeded']);
        Reservation::firstOrFail()->update(['status' => 'confirmed', 'hold_expires_at' => null]);

        $again = $this->book(['simulate' => 'async'], 'tap-2')->assertOk()->json('data');

        $this->assertSame($first['booking_id'], $again['booking_id']);
        $this->assertSame('upcoming', $again['status'], 'The retry is told how the booking stands now');
    }

    // --- Coupons ----------------------------------------------------------------------------------------------------

    public function test_a_coupon_is_taken_off_the_total_and_recorded(): void
    {
        $this->coupon();

        $r = $this->book(['coupon_code' => 'summer20'])->assertCreated();

        $this->assertSame(1200, $r->json('data.total'));
        $res = Reservation::firstOrFail();
        $this->assertEquals([1500, 300, 1200], [$res->subtotal, $res->discount, $res->total]);
        $this->assertEquals(1200, Payment::firstOrFail()->amount);
        $this->assertEquals([$res->id, 300, 'SUMMER20'], [CouponRedemption::firstOrFail()->reservation_id, CouponRedemption::firstOrFail()->discount_amount, CouponRedemption::firstOrFail()->code]);
    }

    public function test_a_coupon_that_cannot_be_used_refuses_the_booking_and_leaves_nothing_behind(): void
    {
        $this->coupon(['code' => 'EXPIRED', 'expires_at' => now()->subDay()]);

        foreach (['EXPIRED', 'NOSUCH'] as $code) {
            $this->book(['coupon_code' => $code])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('coupon_code');
        }

        $this->assertSame([0, 0, 0], [Reservation::count(), Payment::count(), CouponRedemption::count()]);
    }

    public function test_the_last_use_of_a_coupon_goes_to_one_customer(): void
    {
        $this->coupon(['usage_limit' => 1]);
        [, , $other] = $this->signedInCustomer();

        $this->book(['coupon_code' => 'SUMMER20'])->assertCreated();
        $second = $this->book(['coupon_code' => 'SUMMER20'], null, $other)->assertStatus(422);

        $this->assertStringContainsString('used up', $second->json('errors.coupon_code.0'));
        $this->assertSame(1, Reservation::count());
    }

    public function test_a_coupon_with_fee_and_vat_takes_the_discount_off_before_the_vat_but_not_before_the_fee(): void
    {
        config(['customer_app.service_fee_percent' => 10, 'customer_app.vat_percent' => 5]);
        $this->coupon(['value' => 50]);

        $this->book(['coupon_code' => 'SUMMER20'])->assertCreated();

        $res = Reservation::firstOrFail();
        $this->assertEqualsWithDelta([750, 150, 45, 945], [$res->discount, $res->service_fee, $res->tax, $res->total], 0.001);
        $this->assertEqualsWithDelta(750, $res->providerAmount(), 0.001, 'The provider is owed the price after their own coupon');
    }

    // --- Capacity ---------------------------------------------------------------------------------------------------

    public function test_the_last_places_go_to_whoever_books_first(): void
    {
        $this->a['activity']->update(['max_participants' => 2]);
        [, , $other] = $this->signedInCustomer();

        $this->book(['guests' => 2])->assertCreated();
        $second = $this->book(['guests' => 1], null, $other)->assertStatus(409);

        $this->assertSame(['SLOT_UNAVAILABLE', 'This departure is full.'], [$second->json('code'), $second->json('message')]);
        $this->assertSame(1, Reservation::count());
    }

    public function test_more_guests_than_the_departure_can_ever_take_is_a_mistake_in_the_request(): void
    {
        $this->a['activity']->update(['max_participants' => 4]);

        $this->book(['guests' => 5])->assertStatus(422)->assertJsonValidationErrors('guests');
        $this->assertSame(0, Reservation::count());
    }

    public function test_a_departure_that_filled_up_between_the_check_and_the_booking_is_a_conflict_not_a_mistake(): void
    {
        $this->mock(ReservationService::class, fn ($m) => $m->shouldReceive('create')->andThrow(ValidationException::withMessages(['participants' => ['Not enough remaining capacity for this date.']])));

        $this->book()->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    }

    public function test_a_departure_that_does_not_exist_or_has_left_cannot_be_booked(): void
    {
        $this->book(['time' => '10:30'])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->book(['date' => '2026-11-22'])->assertStatus(409);
        $this->assertSame(0, Reservation::count());
    }

    // --- When the payment cannot even be started ---------------------------------------------------------------------

    public function test_if_payment_is_not_available_nothing_is_booked_or_held_and_a_coupon_use_comes_back(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, fn () => new UnavailablePaymentGateway('Gateway is down'));
        $this->a['activity']->update(['max_participants' => 3]);
        $coupon = $this->coupon(['usage_limit' => 1]);

        $this->book(['coupon_code' => 'SUMMER20', 'guests' => 2])->assertStatus(503)->assertJsonPath('code', 'PAYMENT_UNAVAILABLE');

        $this->assertSame('expired', Reservation::firstOrFail()->status->value, 'Let go at once');
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, app(CouponService::class)->usedCount($coupon), 'The coupon use is free again');
        $this->assertSame(3, $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/availability", ['from' => self::NEXT_SATURDAY, 'to' => self::NEXT_SATURDAY])->json('data.days.0.slots.0.spots_left'), 'And so are the places');
        $this->assertSame(0, $this->ca($this->token, 'GET', '/bookings', ['status' => 'pending_payment'])->json('meta.total'));
    }

    // --- The request ------------------------------------------------------------------------------------------------

    public function test_the_contact_and_payment_details_are_checked(): void
    {
        foreach ([['contact_name' => ''], ['contact_name' => 'A'], ['contact_phone' => 'abc'], ['contact_phone' => ''], ['contact_email' => 'nope'], ['payment_method' => 'cash'], ['payment_method' => ''],
            ['guests' => 0], ['date' => '2026-11-14'], ['simulate' => 'maybe']] as $bad) {
            $this->book($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->assertSame(0, Reservation::count());
    }

    public function test_the_email_is_optional(): void
    {
        $this->book(['contact_email' => null])->assertCreated();

        $this->assertNull(Reservation::firstOrFail()->contact_email);
    }

    public function test_the_activity_must_be_one_that_can_be_booked(): void
    {
        $draft = $this->moreActivity($this->a, 'Draft', 100);
        $draft->update(['status' => 'draft']);

        $this->book(['activity_id' => $draft->id])->assertNotFound();
        $this->book(['activity_id' => 99999])->assertNotFound();
        $this->assertSame(0, Reservation::count());
    }

    public function test_booking_is_limited_to_ten_a_minute_with_a_budget_of_its_own(): void
    {
        foreach (range(1, 10) as $i) {
            $this->book(['contact_name' => 'x'])->assertStatus(422);
        }

        $this->book()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->ca($this->token, 'GET', '/bookings')->assertOk();
    }

    // --- One booking ------------------------------------------------------------------------------------------------

    public function test_a_booking_shows_everything_the_specification_lists(): void
    {
        $this->a['activity']->update(['meeting_point' => 'Obhur Marina, Gate 2', 'lat' => 21.7125, 'lng' => 39.0961]);
        $this->coupon();
        $id = $this->book(['guests' => 2, 'coupon_code' => 'SUMMER20'])->json('data.booking_id');

        $d = $this->ca($this->token, 'GET', "/bookings/{$id}")->assertOk()->json('data');

        $this->assertSame(['id', 'reference', 'status', 'date_time', 'guests', 'total', 'can_cancel', 'can_review', 'activity', 'payment_method', 'payment_status', 'subtotal', 'discount', 'service_fee', 'vat',
            'refund_amount', 'coupon', 'cancellation_reason', 'meeting_point', 'host'], array_keys($d));
        $this->assertSame([$id, 'BH-261121-0001', 'upcoming', '2026-11-21T06:00:00Z', 2, 2400, true, false, 'card', 'succeeded', 3000, 600, 0, 0, 0], [$d['id'], $d['reference'], $d['status'], $d['date_time'], $d['guests'], $d['total'],
            $d['can_cancel'], $d['can_review'], $d['payment_method'], $d['payment_status'], $d['subtotal'], $d['discount'], $d['service_fee'], $d['vat'], $d['refund_amount']]);
        $this->assertSame(['code' => 'SUMMER20', 'discount' => 600], $d['coupon']);
        $this->assertSame(['name' => 'Obhur Marina, Gate 2', 'note' => null, 'lat' => 21.7125, 'lng' => 39.0961], $d['meeting_point']);
        $this->assertSame(['id', 'name', 'avatar', 'phone'], array_keys($d['host']));
        $this->assertSame(['id', 'title', 'city', 'image', 'duration_hours', 'rating', 'reviews_count', 'price', 'original_price', 'is_premium', 'is_favorite'], array_keys($d['activity']));
        $this->assertSame(1500, $d['activity']['price'], 'The price of a guest, before the coupon');
    }

    public function test_the_providers_phone_is_shown_once_the_booking_is_confirmed_and_not_before(): void
    {
        $paid = $this->book()->json('data.booking_id');
        $waiting = $this->book(['simulate' => 'async'])->json('data.booking_id');

        $this->assertSame('+966551112233', $this->ca($this->token, 'GET', "/bookings/{$paid}")->json('data.host.phone'));
        $this->assertNull($this->ca($this->token, 'GET', "/bookings/{$waiting}")->json('data.host.phone'));
    }

    public function test_can_review_is_true_for_a_completed_trip_until_it_is_reviewed(): void
    {
        $id = $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed');
        $this->assertTrue($this->ca($this->token, 'GET', "/bookings/{$id}")->json('data.can_review'));
        $this->assertFalse($this->ca($this->token, 'GET', "/bookings/{$id}")->json('data.can_cancel'));

        $this->ca($this->token, 'POST', "/activities/{$this->a['activity']->id}/reviews", ['booking_id' => $id, 'rating' => 5])->assertCreated();

        $this->assertFalse($this->ca($this->token, 'GET', "/bookings/{$id}")->json('data.can_review'));
    }

    public function test_the_statuses_a_customer_sees_follow_the_platforms(): void
    {
        $hold = fn (int $minutes) => ['hold_expires_at' => now()->addMinutes($minutes)->toDateTimeString()];
        $ids = [
            'pending_payment' => $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending', 1500, $hold(10)),
            'expired' => $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending', 1500, $hold(-10)),   // the hold ran out, the system has not marked it yet
            'upcoming' => $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed'),
            'completed' => $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed'),
            'cancelled' => $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'cancelled'),
        ];

        foreach ($ids as $shown => $id) {
            $this->assertSame($shown, $this->ca($this->token, 'GET', "/bookings/{$id}")->json('data.status'));
        }
        $this->assertTrue($this->ca($this->token, 'GET', "/bookings/{$ids['pending_payment']}")->json('data.can_cancel'));
        $this->assertFalse($this->ca($this->token, 'GET', "/bookings/{$ids['expired']}")->json('data.can_cancel'));
    }

    public function test_another_customers_booking_a_made_up_one_and_a_word_are_all_not_found_alike(): void
    {
        [, $other] = $this->signedInCustomer();
        $theirs = $this->makeBooking($this->a, $other, self::NEXT_SATURDAY, 'confirmed');

        $answers = [$this->ca($this->token, 'GET', "/bookings/{$theirs}"), $this->ca($this->token, 'GET', '/bookings/99999'), $this->ca($this->token, 'GET', '/bookings/abc')];

        foreach ($answers as $r) {
            $r->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        }
        $this->assertSame(1, count(array_unique(array_map(fn ($r) => $r->json('message'), $answers))), 'It does not reveal which ids exist');
    }

    // --- The trips list ---------------------------------------------------------------------------------------------

    public function test_the_list_has_counts_over_all_trips_and_the_bookings_in_card_form(): void
    {
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed');
        $this->makeBooking($this->a, $this->customer, '2026-12-05', 'confirmed');
        foreach (range(1, 3) as $i) {
            $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed');
        }
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'cancelled');
        $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending', 1500, ['hold_expires_at' => now()->addMinutes(5)->toDateTimeString()]);
        [, $other] = $this->makeCustomer();
        $this->makeBooking($this->a, $other, self::NEXT_SATURDAY, 'confirmed');   // someone else's: never counted

        $r = $this->ca($this->token, 'GET', '/bookings', ['status' => 'upcoming'])->assertOk();

        $this->assertSame(['upcoming' => 2, 'completed' => 3, 'cancelled' => 1, 'pending_payment' => 1, 'expired' => 0], $r->json('data.counts'));
        $this->assertCount(2, $r->json('data.bookings'));
        $this->assertSame(['id', 'reference', 'status', 'date_time', 'guests', 'total', 'can_cancel', 'can_review', 'activity'], array_keys($r->json('data.bookings.0')));
        $this->assertSame(2, $r->json('meta.total'));
    }

    public function test_each_status_filter_shows_just_those_and_upcoming_is_soonest_first(): void
    {
        $later = $this->makeBooking($this->a, $this->customer, '2026-12-05', 'confirmed');
        $soon = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'confirmed');
        $done = $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed');
        $gone = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'cancelled');
        $waiting = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending', 1500, ['hold_expires_at' => now()->addMinutes(5)->toDateTimeString()]);
        $stale = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'pending', 1500, ['hold_expires_at' => now()->subMinutes(5)->toDateTimeString()]);
        $expired = $this->makeBooking($this->a, $this->customer, self::NEXT_SATURDAY, 'expired');
        $ids = fn (string $s) => array_column($this->ca($this->token, 'GET', '/bookings', ['status' => $s])->assertOk()->json('data.bookings'), 'id');

        $this->assertSame([$soon, $later], $ids('upcoming'));
        $this->assertSame([$done], $ids('completed'));
        $this->assertSame([$gone], $ids('cancelled'));
        $this->assertSame([$waiting], $ids('pending_payment'));
        $this->assertEqualsCanonicalizing([$stale, $expired], $ids('expired'));
        $this->assertCount(7, $this->ca($this->token, 'GET', '/bookings')->json('data.bookings'));
        $this->ca($this->token, 'GET', '/bookings', ['status' => 'nonsense'])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_list_is_paged(): void
    {
        foreach (range(1, 23) as $i) {
            $this->makeBooking($this->a, $this->customer, '2026-11-01', 'completed');
        }

        $r = $this->ca($this->token, 'GET', '/bookings')->assertOk();

        $this->assertSame([20, 23, 2], [count($r->json('data.bookings')), $r->json('meta.total'), $r->json('meta.last_page')]);
        $this->assertCount(3, $this->ca($this->token, 'GET', '/bookings', ['page' => 2])->json('data.bookings'));
        $this->assertSame(23, $this->ca($this->token, 'GET', '/bookings', ['page' => 2])->json('data.counts.completed'), 'The counts do not depend on the page');
    }
}
