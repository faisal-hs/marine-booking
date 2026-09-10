<?php

namespace Tests\Unit;

use App\Enums\ReservationStatus;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ReservationService $service;

    /**
     * makeBookableActivity() creates a Saturday-only (day_of_week => 6)
     * schedule period — the original hardcoded '2026-09-05' was chosen
     * specifically because it fell on a Saturday. This computes the next
     * Saturday from today instead, so it never goes stale, and preserves
     * the exact same Saturday/Sunday relationship the one test using two
     * dates originally relied on.
     */
    protected \Illuminate\Support\Carbon $bookableSaturday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReservationService::class);
        $this->bookableSaturday = Carbon::now()->next(Carbon::SATURDAY);
    }

    protected function makeBookableActivity(): array
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        return [$activity, $option, $customer];
    }

    public function test_created_reservation_snapshots_the_price_and_never_recalculates(): void
    {
        [$activity, $option, $customer] = $this->makeBookableActivity();

        $reservation = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy(), ['adult' => 2]);

        $this->assertEquals(3000.0, (float) $reservation->total);

        // Changing the underlying pricing rule afterwards must NOT affect
        // the already-created reservation's frozen total.
        $option->pricingRules()->first()->update(['price' => 9999]);

        $this->assertEquals(3000.0, (float) $reservation->fresh()->total);
    }

    public function test_created_reservation_starts_as_pending_with_a_hold_expiry(): void
    {
        [$activity, $option, $customer] = $this->makeBookableActivity();

        $reservation = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy(), ['adult' => 1]);

        $this->assertEquals(ReservationStatus::Pending, $reservation->status);
        $this->assertNotNull($reservation->hold_expires_at);
        $this->assertTrue($reservation->hold_expires_at->isFuture());
    }

    public function test_confirm_payment_moves_pending_to_confirmed(): void
    {
        [$activity, $option, $customer] = $this->makeBookableActivity();
        $reservation = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy(), ['adult' => 1]);

        $confirmed = $this->service->confirmPayment($reservation);

        $this->assertEquals(ReservationStatus::Confirmed, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
    }

    public function test_confirm_payment_rejects_an_already_expired_hold(): void
    {
        [$activity, $option, $customer] = $this->makeBookableActivity();
        $reservation = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy(), ['adult' => 1]);
        $reservation->update(['hold_expires_at' => now()->subMinute()]);

        $this->expectException(ValidationException::class);

        $this->service->confirmPayment($reservation->fresh());
    }

    public function test_confirm_payment_rejects_a_non_pending_reservation(): void
    {
        [$activity, $option, $customer] = $this->makeBookableActivity();
        $reservation = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy(), ['adult' => 1]);
        $this->service->confirmPayment($reservation);

        $this->expectException(ValidationException::class);

        $this->service->confirmPayment($reservation->fresh());
    }

    public function test_expire_stale_holds_flips_only_pending_reservations_past_their_hold(): void
    {
        [$activity, $option, $customer] = $this->makeBookableActivity();

        $stillValid = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy(), ['adult' => 1]);
        $alreadyExpired = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy()->addDay(), ['adult' => 1]);
        $alreadyExpired->update(['hold_expires_at' => now()->subMinute()]);

        $count = $this->service->expireStaleHolds();

        $this->assertEquals(1, $count);
        $this->assertEquals(ReservationStatus::Pending, $stillValid->fresh()->status);
        $this->assertEquals(ReservationStatus::Expired, $alreadyExpired->fresh()->status);
    }

    public function test_expired_pending_reservation_no_longer_blocks_a_new_booking_for_the_same_capacity(): void
    {
        // Proves the LIVE capacity check works end-to-end through the
        // Service, not just at the repository level (already proven in
        // ReservationCapacityTest) — an expired hold frees its capacity
        // for a brand new reservation attempt without needing the
        // scheduled expiry command to have run first.
        [$activity, $option, $customer] = $this->makeBookableActivity();
        $option->update(['max_participants' => 2]);

        $firstReservation = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy(), ['adult' => 2]);
        $firstReservation->update(['hold_expires_at' => now()->subMinute()]); // still status=pending in the DB

        $secondReservation = $this->service->create($customer, $activity, $option, null, $this->bookableSaturday->copy(), ['adult' => 2]);

        $this->assertNotNull($secondReservation->id);
    }
}
