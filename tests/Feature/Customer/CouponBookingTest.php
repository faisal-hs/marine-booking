<?php

namespace Tests\Feature\Customer;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\CouponService;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * Using a provider's coupon when booking. The provider funds the discount: the customer pays the total after it, and the provider earns on what the
 * customer paid. The activity costs 1,500 for an adult; today is Sunday 15 November 2026 and it runs on Saturdays.
 */
class CouponBookingTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private User $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
        [$this->customer] = $this->makeCustomer();
        $this->token = $this->tokenFor($this->customer, 'customer');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function coupon(array $attrs = [], ?array $for = null): Coupon
    {
        return Coupon::create($attrs + ['provider_id' => ($for ?? $this->a)['provider']->id, 'code' => 'SUMMER20', 'type' => 'percent', 'value' => 20, 'is_active' => true]);
    }

    private function body(?string $code, array $over = [], ?array $scenario = null): array
    {
        $s = $scenario ?? $this->a;

        // The overrides win over the defaults (array_merge), and the code is set last.
        return array_filter(array_merge(['activity' => $s['activity']->uuid, 'booking_option' => $s['option']->uuid, 'reservation_date' => '2026-11-21', 'participants' => ['adult' => 1]], $over, ['coupon_code' => $code]), fn ($v) => $v !== null);
    }

    private function book(?string $code, array $over = [], ?string $token = null, ?array $scenario = null)
    {
        return $this->hit($token ?? $this->token, 'POST', '/api/v1/customer/reservations', $this->body($code, $over, $scenario));
    }

    private function check(?string $code, array $over = [])
    {
        $body = $this->body($code, $over);
        unset($body['reservation_date']);

        return $this->hit($this->token, 'POST', '/api/v1/customer/coupons/validate', $body);
    }

    // --- The discount ---------------------------------------------------------------------------------------------

    public function test_a_booking_without_a_coupon_costs_what_it_always_did(): void
    {
        $data = $this->book(null)->assertCreated()->json('data');

        $this->assertSame([1500, 0, 1500, null], [$data['subtotal'], $data['discount'], $data['total'], $data['coupon'] ?? null]);
        $this->assertSame(0, CouponRedemption::count());
    }

    public function test_a_percentage_coupon_takes_that_share_off_and_the_booking_shows_it(): void
    {
        $this->coupon();

        $data = $this->book('SUMMER20')->assertCreated()->json('data');

        $this->assertSame([1500, 300, 1200], [$data['subtotal'], $data['discount'], $data['total']]);
        $this->assertSame(['code' => 'SUMMER20', 'discount' => 300], $data['coupon']);
        $reservation = Reservation::firstOrFail();
        $this->assertSame([1500.0, 300.0, 1200.0], [(float) $reservation->subtotal, (float) $reservation->discount, (float) $reservation->total]);
        $redemption = CouponRedemption::firstOrFail();
        $this->assertSame([$reservation->id, 300.0, 'SUMMER20'], [$redemption->reservation_id, $redemption->discount_amount, $redemption->code]);
    }

    public function test_the_code_works_in_any_case_and_with_spaces_around_it(): void
    {
        $this->coupon();

        $this->book(' summer20 ')->assertCreated()->assertJsonPath('data.discount', 300);
    }

    public function test_a_fixed_coupon_takes_that_amount_off(): void
    {
        $this->coupon(['code' => 'OFF100', 'type' => 'fixed', 'value' => 100]);

        $this->book('OFF100')->assertCreated()->assertJsonPath('data.total', 1400);
    }

    public function test_a_percentage_coupon_never_takes_more_than_its_cap(): void
    {
        $this->coupon(['code' => 'HALF', 'value' => 50, 'max_discount' => 200]);

        $this->book('HALF')->assertCreated()->assertJsonPath('data.discount', 200)->assertJsonPath('data.total', 1300);
    }

    public function test_however_big_the_coupon_a_booking_never_costs_less_than_the_minimum(): void
    {
        $this->coupon(['code' => 'FREE', 'value' => 100]);
        $this->coupon(['code' => 'HUGE', 'type' => 'fixed', 'value' => 99999]);

        $this->book('FREE')->assertCreated()->assertJsonPath('data.total', 1)->assertJsonPath('data.discount', 1499);
        $this->book('HUGE', ['reservation_date' => '2026-11-28'])->assertCreated()->assertJsonPath('data.total', 1);

        config(['coupons.minimum_total' => 25]);
        $this->book('FREE', ['reservation_date' => '2026-12-05'])->assertCreated()->assertJsonPath('data.total', 25);
    }

    public function test_the_discount_applies_to_every_participant_priced_booking(): void
    {
        $this->coupon(['code' => 'HALF', 'value' => 50]);

        $data = $this->book('HALF', ['participants' => ['adult' => 3]])->assertCreated()->json('data');

        $this->assertSame([4500, 2250, 2250], [$data['subtotal'], $data['discount'], $data['total']]);
    }

    // --- When a coupon cannot be used ----------------------------------------------------------------------------

    public function test_a_coupon_that_cannot_be_used_refuses_the_booking_with_the_reason_and_books_nothing(): void
    {
        [$second] = $this->makeBookableActivity($this->a['provider'], 'published', ['title' => 'Sunset Cruise']);
        $this->coupon(['code' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->coupon(['code' => 'SECOND', 'activity_ids' => []])->activities()->sync([$second->id]);
        $this->coupon(['code' => 'BIGONLY', 'min_order_amount' => 2000]);

        $cases = ['EXPIRED' => 'has expired', 'OFF' => 'not valid', 'NOSUCH' => 'not valid', 'SECOND' => 'cannot be used for this activity', 'BIGONLY' => 'at least 2,000.00'];

        foreach ($cases as $code => $reason) {
            $response = $this->book($code)->assertStatus(422)->assertJsonValidationErrors('coupon_code');
            $this->assertStringContainsString($reason, $response->json('errors.coupon_code.0'), $code);
        }
        $this->assertSame([0, 0], [Reservation::count(), CouponRedemption::count()], 'A refused coupon books nothing');
    }

    public function test_an_unknown_code_and_a_switched_off_one_are_answered_alike(): void
    {
        $this->coupon(['code' => 'OFF', 'is_active' => false]);

        $off = $this->book('OFF')->json('errors.coupon_code.0');
        $unknown = $this->book('NOSUCH')->json('errors.coupon_code.0');

        $this->assertSame($unknown, $off, 'Nothing tells you which codes exist');
    }

    public function test_another_providers_code_cannot_be_used_on_this_providers_activity(): void
    {
        $other = $this->approvedProvider();
        $this->coupon(['code' => 'THEIRS'], $other);

        $this->book('THEIRS')->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->book('THEIRS', [], null, $other)->assertCreated()->assertJsonPath('data.discount', 300);
    }

    public function test_the_activity_list_limits_where_a_coupon_works_and_an_empty_list_means_all(): void
    {
        [$second] = $this->makeBookableActivity($this->a['provider'], 'published', ['title' => 'Sunset Cruise']);
        $second->update(['status' => 'published']);
        $second->bookingOptions()->first();
        $only = $this->coupon(['code' => 'ONLYREEF']);
        $only->activities()->sync([$this->a['activity']->id]);
        $this->coupon(['code' => 'EVERYWHERE']);

        $this->book('ONLYREEF')->assertCreated();
        $this->book('EVERYWHERE', ['reservation_date' => '2026-11-28'])->assertCreated()->assertJsonPath('data.discount', 300);
    }

    public function test_only_one_coupon_can_be_sent_with_a_booking(): void
    {
        $this->coupon();

        $this->hit($this->token, 'POST', '/api/v1/customer/reservations', $this->body(null) + ['coupon_code' => ['SUMMER20', 'OTHER']])->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->assertSame(0, Reservation::count());
    }

    // --- The number of uses ------------------------------------------------------------------------------------

    public function test_the_last_use_goes_to_one_booking_only(): void
    {
        $this->coupon(['usage_limit' => 1]);

        $this->book('SUMMER20')->assertCreated();
        $second = $this->book('SUMMER20', ['reservation_date' => '2026-11-28'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $this->assertStringContainsString('used up', $second->json('errors.coupon_code.0'));
        $this->assertSame(1, Reservation::count());
    }

    public function test_a_use_is_given_back_when_the_booking_is_cancelled_or_expires(): void
    {
        $coupon = $this->coupon(['usage_limit' => 1]);
        $first = $this->book('SUMMER20')->assertCreated()->json('data.id');

        foreach (['cancelled', 'expired'] as $dead) {
            Reservation::find($first)->forceFill(['status' => $dead])->save();
            $again = $this->book('SUMMER20', ['reservation_date' => '2026-11-28'])->assertCreated()->json('data.id');
            $this->assertSame(1, app(CouponService::class)->usedCount($coupon->refresh()), "Counted once after the booking was {$dead}");
            Reservation::find($again)->forceFill(['status' => 'cancelled'])->save();
        }
        $this->assertSame(3, CouponRedemption::count(), 'The history is whole: nothing was deleted');
    }

    public function test_a_pending_booking_holds_its_use_so_nobody_can_take_it_meanwhile(): void
    {
        $this->coupon(['usage_limit' => 1]);
        $this->book('SUMMER20')->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->book('SUMMER20', ['reservation_date' => '2026-11-28'])->assertStatus(422);
    }

    // --- Paying and earning ------------------------------------------------------------------------------------

    public function test_the_provider_earns_on_what_the_customer_paid_and_the_ledger_remembers_the_discount(): void
    {
        $this->coupon();
        $id = $this->book('SUMMER20')->assertCreated()->json('data.id');
        Reservation::find($id)->forceFill(['status' => 'completed'])->save();

        app(LedgerService::class)->syncBooking(Reservation::find($id));

        $entries = WalletEntry::where('reservation_id', $id)->orderBy('id')->get();
        $this->assertSame([['booking_income', 1200.0], ['platform_fee', -120.0]], $entries->map(fn ($e) => [$e->type, $e->amount])->all(), 'Income is the 1,200 paid, and the 10% commission is on that');
        $this->assertEquals(300, $entries[0]->meta['discount']);
        $this->assertSame(1080.0, app(LedgerService::class)->balances($this->a['provider'])['total_earnings']);
    }

    public function test_a_coupon_booking_that_is_refunded_is_worked_out_on_what_was_paid(): void
    {
        $this->coupon();
        $id = $this->book('SUMMER20')->assertCreated()->json('data.id');
        $reservation = Reservation::find($id);
        $reservation->forceFill(['status' => 'completed'])->save();
        $this->makePayment($id, 1200, 200);

        app(LedgerService::class)->syncBooking($reservation->refresh());

        $this->assertSame(900.0, round(WalletEntry::where('reservation_id', $id)->sum('amount'), 2), '1200 paid - 200 refunded - 10% of the 1000 kept');
    }

    // --- Checking before booking -------------------------------------------------------------------------------

    public function test_a_customer_can_see_what_a_coupon_would_take_off_before_booking(): void
    {
        $this->coupon();

        $data = $this->check('summer20')->assertOk()->json('data');

        $this->assertSame(['code' => 'SUMMER20', 'type' => 'percent', 'value' => 20, 'currency' => 'SAR', 'subtotal' => 1500, 'discount' => 300, 'total' => 1200], $data);
        $this->assertSame([0, 0], [Reservation::count(), CouponRedemption::count()], 'Checking uses nothing up');
    }

    public function test_checking_refuses_for_the_same_reasons_booking_does(): void
    {
        $this->coupon(['code' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $this->coupon(['code' => 'USEDUP', 'usage_limit' => 1]);
        $this->book('USEDUP')->assertCreated();

        $this->check('EXPIRED')->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->check('USEDUP')->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->check('NOSUCH')->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->check(null)->assertStatus(422)->assertJsonValidationErrors('coupon_code');
    }

    public function test_checking_for_an_activity_that_is_not_live_is_not_found(): void
    {
        $this->coupon();
        $this->a['activity']->update(['status' => 'inactive']);

        $this->check('SUMMER20')->assertNotFound();
    }

    public function test_checking_needs_a_customer_and_is_limited_to_twenty_a_minute_with_its_own_budget(): void
    {
        $this->hit(null, 'POST', '/api/v1/customer/coupons/validate', [])->assertUnauthorized();
        $this->hit($this->a['token'], 'POST', '/api/v1/customer/coupons/validate', $this->body('X'))->assertForbidden();

        foreach (range(1, 20) as $n) {
            $this->check('GUESS'.$n)->assertStatus(422);
        }
        $this->check('GUESS21')->assertStatus(429);
        $this->book(null)->assertCreated();   // booking has its own budget
    }

    // --- Where the booking came from -----------------------------------------------------------------------------

    public function test_the_booking_remembers_how_the_customer_arrived(): void
    {
        foreach (['social' => 'social', 'category' => 'category', 'recommendations' => 'recommendations', 'direct' => 'direct', 'nonsense' => 'direct', '' => 'direct'] as $given => $stored) {
            $date = Carbon::parse('2026-11-21')->addWeeks(array_search($given, array_keys(['social' => 1, 'category' => 1, 'recommendations' => 1, 'direct' => 1, 'nonsense' => 1, '' => 1])))->toDateString();

            $id = $this->hit($this->token, 'POST', '/api/v1/customer/reservations?source='.$given, $this->body(null, ['reservation_date' => $date]))->assertCreated()->json('data.id');

            $this->assertSame($stored, Reservation::find($id)->source, "Sent: '{$given}'");
        }
    }
}
