<?php

namespace Tests\Unit;

use App\Enums\ReservationStatus;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function makeReservation(array $overrides = []): Reservation
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
        ]);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        return Reservation::create(array_merge([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => now()->subDay()->toDateString(),
            'status' => 'confirmed',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 3000,
            'total' => 3000,
            'pricing_breakdown' => [],
        ], $overrides));
    }

    public function test_confirmed_reservation_with_a_past_date_becomes_completed(): void
    {
        $reservation = $this->makeReservation(['reservation_date' => now()->subDay()->toDateString()]);

        $count = app(ReservationService::class)->completePastReservations();

        $this->assertEquals(1, $count);
        $this->assertEquals(ReservationStatus::Completed, $reservation->fresh()->status);
    }

    public function test_confirmed_reservation_with_todays_date_is_not_yet_completed(): void
    {
        $reservation = $this->makeReservation(['reservation_date' => now()->toDateString()]);

        $count = app(ReservationService::class)->completePastReservations();

        $this->assertEquals(0, $count);
        $this->assertEquals(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_confirmed_reservation_with_a_future_date_is_untouched(): void
    {
        $reservation = $this->makeReservation(['reservation_date' => now()->addDay()->toDateString()]);

        app(ReservationService::class)->completePastReservations();

        $this->assertEquals(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_pending_reservation_with_a_past_date_is_never_completed(): void
    {
        $reservation = $this->makeReservation(['status' => 'pending', 'reservation_date' => now()->subDay()->toDateString()]);

        app(ReservationService::class)->completePastReservations();

        $this->assertEquals(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    public function test_cancelled_reservation_with_a_past_date_is_never_completed(): void
    {
        $reservation = $this->makeReservation(['status' => 'cancelled', 'reservation_date' => now()->subDay()->toDateString()]);

        app(ReservationService::class)->completePastReservations();

        $this->assertEquals(ReservationStatus::Cancelled, $reservation->fresh()->status);
    }

    public function test_multiple_past_confirmed_reservations_all_complete_independently(): void
    {
        $reservationA = $this->makeReservation(['reservation_date' => now()->subDays(2)->toDateString()]);
        $reservationB = $this->makeReservation(['reservation_date' => now()->subDay()->toDateString()]);

        $count = app(ReservationService::class)->completePastReservations();

        $this->assertEquals(2, $count);
        $this->assertEquals(ReservationStatus::Completed, $reservationA->fresh()->status);
        $this->assertEquals(ReservationStatus::Completed, $reservationB->fresh()->status);
    }
}
