<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET, POST, PATCH and DELETE /provider/coupons. The provider funds their own coupons (there are none funded by the platform); a coupon is switched
 * off, never deleted; a code is unique among the provider's own; another provider's coupon is "not found".
 */
class ProviderCouponsApiTest extends TestCase
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

    private function send(string $method, string $path, array $body = [], ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], $method, '/api/v1/provider/coupons'.$path, $body);
    }

    private function create(array $over = [], ?array $as = null)
    {
        return $this->send('POST', '', $over + ['code' => 'SUMMER20', 'type' => 'percent', 'value' => 20], $as);
    }

    private function coupon(array $attrs = [], ?array $for = null): Coupon
    {
        return Coupon::create($attrs + ['provider_id' => ($for ?? $this->a)['provider']->id, 'code' => 'CODE'.uniqid(), 'type' => 'percent', 'value' => 10, 'is_active' => true]);
    }

    /** A booking that used the coupon, in the given state. */
    private function use(Coupon $coupon, string $status = 'confirmed'): Reservation
    {
        $reservation = Reservation::find($this->makeBooking($this->a, $this->customerNamed('C'.uniqid()), '2026-11-21', $status, 1500));
        CouponRedemption::create(['coupon_id' => $coupon->id, 'reservation_id' => $reservation->id, 'customer_id' => $reservation->customer_id, 'code' => $coupon->code, 'discount_amount' => 100, 'created_at' => now()]);

        return $reservation;
    }

    // --- Creating ---------------------------------------------------------------------------------------------------

    public function test_a_coupon_is_created_with_the_specs_fields(): void
    {
        $response = $this->create(['usage_limit' => 100, 'expires_at' => '2026-12-31T23:59:59Z'])->assertCreated();

        $this->assertSame(['status' => true, 'message' => 'Coupon created'], array_intersect_key($response->json(), ['status' => 1, 'message' => 1]));
        $data = $response->json('data');
        $this->assertSame(['id', 'code', 'type', 'value', 'used_count', 'usage_limit', 'max_discount', 'min_order_amount', 'expires_at', 'activity_ids', 'status'], array_keys($data));
        $this->assertSame(['SUMMER20', 'percent', 20, 0, 100, '2026-12-31T23:59:59Z', [], 'active'], [$data['code'], $data['type'], $data['value'], $data['used_count'], $data['usage_limit'], $data['expires_at'], $data['activity_ids'], $data['status']]);
        $this->assertSame($this->a['provider']->id, Coupon::firstOrFail()->provider_id);
    }

    public function test_the_code_is_kept_in_capitals_and_unique_among_the_providers_own(): void
    {
        $this->create(['code' => 'summer20'])->assertCreated()->assertJsonPath('data.code', 'SUMMER20');

        foreach (['SUMMER20', 'summer20', 'Summer20'] as $again) {
            $this->create(['code' => $again])->assertStatus(422)->assertJsonValidationErrors('code');
        }
        $this->assertSame(1, Coupon::count());
    }

    public function test_two_providers_may_use_the_same_code(): void
    {
        $other = $this->approvedProvider();

        $this->create(['code' => 'WELCOME'])->assertCreated();
        $this->create(['code' => 'WELCOME'], $other)->assertCreated();

        $this->assertSame(2, Coupon::where('code', 'WELCOME')->count());
    }

    public function test_the_code_must_be_letters_digits_dashes_and_underscores(): void
    {
        foreach (['ab', str_repeat('A', 41), 'has space', 'SUMMER!', 'قسيمة', ''] as $bad) {
            $this->create(['code' => $bad])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('code');
        }
        foreach (['ABC', 'summer-20', 'SUMMER_20', str_repeat('A', 40)] as $good) {
            $this->create(['code' => $good])->assertCreated();
        }
    }

    public function test_a_percentage_is_more_than_zero_and_at_most_a_hundred_and_a_fixed_amount_is_any_positive_amount(): void
    {
        foreach ([0, -5, 100.01, 150, 'lots'] as $bad) {
            $this->create(['code' => 'P'.uniqid(), 'type' => 'percent', 'value' => $bad])->assertStatus(422)->assertJsonValidationErrors('value');
        }
        $this->create(['code' => 'FULL', 'type' => 'percent', 'value' => 100])->assertCreated();
        $this->create(['code' => 'TINY', 'type' => 'percent', 'value' => 0.5])->assertCreated();

        foreach ([0, -1, 'lots'] as $bad) {
            $this->create(['code' => 'F'.uniqid(), 'type' => 'fixed', 'value' => $bad])->assertStatus(422)->assertJsonValidationErrors('value');
        }
        $this->create(['code' => 'BIG', 'type' => 'fixed', 'value' => 5000])->assertCreated()->assertJsonPath('data.value', 5000);
    }

    public function test_the_other_fields_are_checked(): void
    {
        $bad = [['type' => 'bogus'], ['usage_limit' => 0], ['usage_limit' => 'many'], ['max_discount' => 0], ['min_order_amount' => -1], ['expires_at' => '2026-01-01T00:00:00Z'], ['expires_at' => 'tomorrow-ish'], ['activity_ids' => 'all'], ['activity_ids' => ['x']]];

        foreach ($bad as $over) {
            $this->create($over + ['code' => 'B'.uniqid()])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->assertSame(0, Coupon::count());
        $this->send('POST', '', [])->assertStatus(422);
    }

    public function test_a_coupon_can_be_limited_to_the_providers_own_activities_only(): void
    {
        [$second] = $this->makeBookableActivity($this->a['provider'], 'published', ['title' => 'Sunset Cruise']);
        $other = $this->approvedProvider();

        $ok = $this->create(['activity_ids' => [$second->id, $this->a['activity']->id, $second->id]])->assertCreated()->json('data.activity_ids');
        $this->assertSame([$this->a['activity']->id, $second->id], $ok, 'Sorted, with no repeats');

        $this->create(['code' => 'THEIRS', 'activity_ids' => [$other['activity']->id]])->assertStatus(422)->assertJsonValidationErrors('activity_ids.0');
        $this->create(['code' => 'GHOST', 'activity_ids' => [99999999]])->assertStatus(422);
        $this->assertSame(1, Coupon::count());
    }

    // --- Listing ------------------------------------------------------------------------------------------------------

    public function test_the_list_has_the_specs_fields_and_only_the_providers_own_newest_first(): void
    {
        $other = $this->approvedProvider();
        $older = $this->coupon(['code' => 'OLDER']);
        $newer = $this->coupon(['code' => 'NEWER', 'type' => 'fixed', 'value' => 50, 'usage_limit' => 10]);
        $this->coupon(['code' => 'THEIRS'], $other);

        $response = $this->send('GET', '')->assertOk();

        $this->assertSame([$newer->id, $older->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['id', 'code', 'type', 'value', 'used_count', 'usage_limit', 'max_discount', 'min_order_amount', 'expires_at', 'activity_ids', 'status'], array_keys($response->json('data.0')));
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 2], $response->json('meta'));
    }

    public function test_the_list_can_be_filtered_by_status_and_paged(): void
    {
        $this->coupon(['code' => 'LIVE']);
        $this->coupon(['code' => 'LATER', 'expires_at' => now()->addDay()]);
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->coupon(['code' => 'GONE', 'expires_at' => now()->subDay()]);
        $this->coupon(['code' => 'OFFGONE', 'is_active' => false, 'expires_at' => now()->subDay()]);

        $codes = fn (string $q) => collect($this->send('GET', $q)->assertOk()->json('data'))->pluck('code')->sort()->values()->all();

        $this->assertSame(['LATER', 'LIVE'], $codes('?status=active'));
        $this->assertSame(['GONE'], $codes('?status=expired'));
        $this->assertSame(['OFF', 'OFFGONE'], $codes('?status=inactive'), 'Switched off beats expired');
        $this->assertCount(5, $codes(''));
        $this->assertSame(2, $this->send('GET', '?per_page=2')->json('meta.last_page') - 1);
        $this->send('GET', '?status=nonsense')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_used_count_counts_only_bookings_that_are_still_alive(): void
    {
        $coupon = $this->coupon(['code' => 'USED', 'usage_limit' => 10]);
        foreach (['pending', 'confirmed', 'completed'] as $alive) {
            $this->use($coupon, $alive);
        }
        foreach (['cancelled', 'expired'] as $dead) {
            $this->use($coupon, $dead);
        }

        $this->assertSame(3, $this->send('GET', '')->json('data.0.used_count'), 'A use is given back when the booking is not kept');
    }

    // --- Updating ---------------------------------------------------------------------------------------------------------

    public function test_a_coupon_can_be_changed_between_percent_and_fixed(): void
    {
        $id = $this->create()->json('data.id');

        $to = $this->send('PATCH', "/{$id}", ['type' => 'fixed', 'value' => 50, 'usage_limit' => 150, 'expires_at' => '2027-01-31T23:59:59Z', 'status' => 'active'])->assertOk();

        $this->assertSame('Coupon updated', $to->json('message'));
        $this->assertSame(['SUMMER20', 'fixed', 50, 150, '2027-01-31T23:59:59Z', 'active'], [$to->json('data.code'), $to->json('data.type'), $to->json('data.value'), $to->json('data.usage_limit'), $to->json('data.expires_at'), $to->json('data.status')]);

        $this->send('PATCH', "/{$id}", ['type' => 'percent', 'value' => 15])->assertOk()->assertJsonPath('data.type', 'percent');
    }

    public function test_a_change_is_checked_as_the_coupon_would_be_afterwards(): void
    {
        $fixed = $this->create(['code' => 'FIXED150', 'type' => 'fixed', 'value' => 150])->json('data.id');
        $percent = $this->create(['code' => 'PCT20', 'type' => 'percent', 'value' => 20])->json('data.id');

        $this->send('PATCH', "/{$fixed}", ['type' => 'percent'])->assertStatus(422)->assertJsonValidationErrors('value');   // 150 percent
        $this->send('PATCH', "/{$percent}", ['value' => 120])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->send('PATCH', "/{$percent}", ['type' => 'fixed', 'value' => 120])->assertOk();

        $this->assertSame('fixed', Coupon::find($fixed)->type, 'The refused change did not apply');
    }

    public function test_the_code_cannot_be_changed(): void
    {
        $id = $this->create()->json('data.id');

        $this->send('PATCH', "/{$id}", ['code' => 'RENAMED'])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertSame('SUMMER20', Coupon::find($id)->code);
    }

    public function test_the_limit_cannot_go_below_the_uses_already_taken(): void
    {
        $coupon = $this->coupon(['code' => 'LIMITED', 'usage_limit' => 10]);
        $this->use($coupon);
        $this->use($coupon);
        $this->use($coupon, 'cancelled');   // given back: not counted

        $this->send('PATCH', "/{$coupon->id}", ['usage_limit' => 1])->assertStatus(422)->assertJsonValidationErrors('usage_limit');
        $this->send('PATCH', "/{$coupon->id}", ['usage_limit' => 2])->assertOk()->assertJsonPath('data.usage_limit', 2);
        $this->send('PATCH', "/{$coupon->id}", ['usage_limit' => null])->assertOk()->assertJsonPath('data.usage_limit', null);
    }

    public function test_the_activities_can_be_replaced_or_cleared(): void
    {
        [$second] = $this->makeBookableActivity($this->a['provider'], 'published', ['title' => 'Sunset Cruise']);
        $id = $this->create(['activity_ids' => [$this->a['activity']->id]])->json('data.id');

        $this->send('PATCH', "/{$id}", ['activity_ids' => [$second->id]])->assertOk()->assertJsonPath('data.activity_ids', [$second->id]);
        $this->send('PATCH', "/{$id}", ['value' => 25])->assertOk()->assertJsonPath('data.activity_ids', [$second->id]);   // untouched when not sent
        $this->send('PATCH', "/{$id}", ['activity_ids' => []])->assertOk()->assertJsonPath('data.activity_ids', []);
    }

    public function test_a_coupon_can_be_switched_off_and_on_and_the_date_removed(): void
    {
        $id = $this->create(['expires_at' => '2026-12-31T23:59:59Z'])->json('data.id');

        $this->send('PATCH', "/{$id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->send('PATCH', "/{$id}", ['status' => 'active', 'expires_at' => null])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.expires_at', null);
        $this->send('PATCH', "/{$id}", ['status' => 'expired'])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // --- Deactivating -------------------------------------------------------------------------------------------------------

    public function test_deleting_switches_it_off_and_keeps_everything(): void
    {
        $coupon = $this->coupon(['code' => 'KEEP']);
        $booking = $this->use($coupon);

        $response = $this->send('DELETE', "/{$coupon->id}")->assertOk();

        $this->assertSame('Coupon deactivated', $response->json('message'));
        $this->assertSame(['id' => $coupon->id, 'status' => 'inactive'], $response->json('data'));
        $this->assertFalse($coupon->refresh()->is_active);
        $this->assertSame(1, CouponRedemption::where('reservation_id', $booking->id)->count(), 'The booking keeps its coupon');
        $this->send('DELETE', "/{$coupon->id}")->assertOk();   // again: still switched off
        $this->assertSame('inactive', $this->send('GET', '')->json('data.0.status'));
    }

    // --- Whose coupon it is ----------------------------------------------------------------------------------------------

    public function test_another_providers_coupon_and_a_missing_one_are_not_found_and_alike(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->coupon(['code' => 'THEIRS'], $other);

        foreach (['PATCH', 'DELETE'] as $method) {
            $foreign = $this->send($method, "/{$theirs->id}", ['value' => 5]);
            $missing = $this->send($method, '/99999999', ['value' => 5]);
            $text = $this->send($method, '/abc', ['value' => 5]);
            foreach ([$foreign, $missing, $text] as $r) {
                $r->assertNotFound();
                $this->assertSame('RESOURCE_NOT_FOUND', $r->json('code'));
            }
            $this->assertSame($foreign->json('message'), $missing->json('message'), 'It must not reveal which ids exist');
        }
        $this->assertSame([10.0, true], [$theirs->refresh()->value, $theirs->is_active]);
    }

    public function test_a_bad_body_does_not_tell_a_foreign_coupon_from_a_missing_one(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->coupon(['code' => 'THEIRS'], $other);

        $foreign = $this->send('PATCH', "/{$theirs->id}", ['value' => -1]);
        $missing = $this->send('PATCH', '/99999999', ['value' => -1]);

        $this->assertSame([404, 404], [$foreign->getStatusCode(), $missing->getStatusCode()], 'Looked up first, validated after');
    }

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        $this->create([], $this->unapprovedProvider())->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'GET', '/api/v1/provider/coupons')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'GET', '/api/v1/provider/coupons')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->assertSame(0, Coupon::count());
    }

    public function test_changes_are_limited_to_thirty_a_minute_with_a_budget_of_their_own(): void
    {
        foreach (range(1, 30) as $n) {
            $this->send('POST', '', [])->assertStatus(422);
        }

        $this->send('POST', '', [])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->send('GET', '')->assertOk();
        $this->hit($this->a['token'], 'PUT', '/api/v1/provider/bank-account', ['iban' => 'nope'])->assertStatus(422);
    }
}
