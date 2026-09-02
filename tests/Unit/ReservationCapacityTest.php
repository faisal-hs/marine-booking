<?php

namespace Tests\Unit;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected ReservationRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(ReservationRepositoryInterface::class);
    }

    protected function makeOption(string $model = 'per_person'): ActivityBookingOption
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

        return $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => $model]);
    }

    protected function makeCustomer(): Customer
    {
        $user = User::factory()->customer()->create();

        return Customer::create(['user_id' => $user->id]);
    }

    protected function makeReservation(ActivityBookingOption $option, Customer $customer, array $overrides = []): Reservation
    {
        return Reservation::create(array_merge([
            'activity_id' => $option->activity_id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => '2026-09-05',
            'participants' => ['adult' => 2],
            'status' => 'confirmed',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 3000,
            'total' => 3000,
            'pricing_breakdown' => [],
        ], $overrides));
    }

    public function test_zero_reservations_means_zero_consumed_capacity(): void
    {
        $option = $this->makeOption();

        $result = $this->repository->consumedCapacity($option->id, null, '2026-09-05');

        $this->assertEquals(['participants' => 0, 'quantity' => 0], $result);
    }

    public function test_confirmed_reservation_counts_toward_capacity(): void
    {
        $option = $this->makeOption();
        $customer = $this->makeCustomer();
        $this->makeReservation($option, $customer, ['status' => 'confirmed', 'participants' => ['adult' => 2, 'child' => 1]]);

        $result = $this->repository->consumedCapacity($option->id, null, '2026-09-05');

        $this->assertEquals(3, $result['participants']);
    }

    public function test_pending_reservation_with_unexpired_hold_counts_toward_capacity(): void
    {
        $option = $this->makeOption();
        $customer = $this->makeCustomer();
        $this->makeReservation($option, $customer, [
            'status' => 'pending',
            'hold_expires_at' => Carbon::now()->addMinutes(10),
            'participants' => ['adult' => 2],
        ]);

        $result = $this->repository->consumedCapacity($option->id, null, '2026-09-05');

        $this->assertEquals(2, $result['participants']);
    }

    public function test_pending_reservation_with_expired_hold_does_not_count(): void
    {
        // This is the core correctness guarantee: an expired hold stops
        // blocking capacity immediately, with NO dependency on a scheduled
        // job having already flipped its status to 'expired'.
        $option = $this->makeOption();
        $customer = $this->makeCustomer();
        $this->makeReservation($option, $customer, [
            'status' => 'pending',
            'hold_expires_at' => Carbon::now()->subMinutes(1),
            'participants' => ['adult' => 2],
        ]);

        $result = $this->repository->consumedCapacity($option->id, null, '2026-09-05');

        $this->assertEquals(0, $result['participants']);
    }

    public function test_cancelled_reservation_does_not_count(): void
    {
        $option = $this->makeOption();
        $customer = $this->makeCustomer();
        $this->makeReservation($option, $customer, ['status' => 'cancelled', 'participants' => ['adult' => 2]]);

        $result = $this->repository->consumedCapacity($option->id, null, '2026-09-05');

        $this->assertEquals(0, $result['participants']);
    }

    public function test_expired_status_reservation_does_not_count(): void
    {
        $option = $this->makeOption();
        $customer = $this->makeCustomer();
        $this->makeReservation($option, $customer, ['status' => 'expired', 'participants' => ['adult' => 2]]);

        $result = $this->repository->consumedCapacity($option->id, null, '2026-09-05');

        $this->assertEquals(0, $result['participants']);
    }

    public function test_multiple_active_reservations_sum_correctly(): void
    {
        $option = $this->makeOption();
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        $this->makeReservation($option, $customerA, ['status' => 'confirmed', 'participants' => ['adult' => 2]]);
        $this->makeReservation($option, $customerB, ['status' => 'confirmed', 'participants' => ['adult' => 3, 'child' => 1]]);

        $result = $this->repository->consumedCapacity($option->id, null, '2026-09-05');

        $this->assertEquals(6, $result['participants']);
    }

    public function test_different_schedule_periods_are_isolated_from_each_other(): void
    {
        $option = $this->makeOption();
        $customer = $this->makeCustomer();
        $periodA = $option->activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $periodB = $option->activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '17:00', 'end_time' => '21:00']);

        $this->makeReservation($option, $customer, ['schedule_period_id' => $periodA->id, 'participants' => ['adult' => 2]]);
        $this->makeReservation($option, $customer, ['schedule_period_id' => $periodB->id, 'participants' => ['adult' => 5]]);

        $resultA = $this->repository->consumedCapacity($option->id, $periodA->id, '2026-09-05');
        $resultB = $this->repository->consumedCapacity($option->id, $periodB->id, '2026-09-05');

        $this->assertEquals(2, $resultA['participants']);
        $this->assertEquals(5, $resultB['participants']);
    }

    public function test_null_period_reservations_are_isolated_from_period_specific_ones(): void
    {
        $option = $this->makeOption();
        $customer = $this->makeCustomer();
        $period = $option->activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);

        $this->makeReservation($option, $customer, ['schedule_period_id' => null, 'participants' => ['adult' => 2]]);
        $this->makeReservation($option, $customer, ['schedule_period_id' => $period->id, 'participants' => ['adult' => 9]]);

        $resultGeneral = $this->repository->consumedCapacity($option->id, null, '2026-09-05');
        $resultPeriod = $this->repository->consumedCapacity($option->id, $period->id, '2026-09-05');

        $this->assertEquals(2, $resultGeneral['participants']);
        $this->assertEquals(9, $resultPeriod['participants']);
    }

    public function test_different_dates_are_isolated_from_each_other(): void
    {
        $option = $this->makeOption();
        $customer = $this->makeCustomer();

        $this->makeReservation($option, $customer, ['reservation_date' => '2026-09-05', 'participants' => ['adult' => 2]]);
        $this->makeReservation($option, $customer, ['reservation_date' => '2026-09-06', 'participants' => ['adult' => 7]]);

        $resultDay1 = $this->repository->consumedCapacity($option->id, null, '2026-09-05');
        $resultDay2 = $this->repository->consumedCapacity($option->id, null, '2026-09-06');

        $this->assertEquals(2, $resultDay1['participants']);
        $this->assertEquals(7, $resultDay2['participants']);
    }

    public function test_quantity_based_reservations_sum_separately_from_participants(): void
    {
        $option = $this->makeOption('per_unit');
        $customer = $this->makeCustomer();

        $this->makeReservation($option, $customer, [
            'status' => 'confirmed',
            'participants' => null,
            'quantity' => 3,
            'pricing_model' => 'per_unit',
        ]);
        $this->makeReservation($option, $customer, [
            'status' => 'confirmed',
            'participants' => null,
            'quantity' => 2,
            'pricing_model' => 'per_unit',
        ]);

        $result = $this->repository->consumedCapacity($option->id, null, '2026-09-05');

        $this->assertEquals(5, $result['quantity']);
        $this->assertEquals(0, $result['participants']);
    }

    public function test_different_booking_options_on_the_same_activity_are_isolated(): void
    {
        $optionA = $this->makeOption();
        $optionB = $optionA->activity->bookingOptions()->create(['name' => 'Private', 'slug' => 'private-' . uniqid(), 'booking_model' => 'per_trip']);
        $customer = $this->makeCustomer();

        $this->makeReservation($optionA, $customer, ['participants' => ['adult' => 2]]);
        $this->makeReservation($optionB, $customer, ['participants' => null, 'pricing_model' => 'per_trip']);

        $resultA = $this->repository->consumedCapacity($optionA->id, null, '2026-09-05');
        $resultB = $this->repository->consumedCapacity($optionB->id, null, '2026-09-05');

        $this->assertEquals(2, $resultA['participants']);
        $this->assertEquals(0, $resultB['participants']);
    }
}
