<?php

namespace Tests\Unit;

use App\Enums\BookingModel;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivitySchedulePeriod;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use App\Services\ActivityPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ActivityPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ActivityPricingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ActivityPricingService::class);
    }

    protected function makeActivity(): Activity
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        return Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
        ]);
    }

    protected function makeOption(Activity $activity, BookingModel $model, array $overrides = []): ActivityBookingOption
    {
        return $activity->bookingOptions()->create(array_merge([
            'name' => 'Test Option',
            'slug' => 'test-option-' . uniqid(),
            'booking_model' => $model->value,
        ], $overrides));
    }

    // --- Per Person (spec §10, task example: 2 adults + 1 child = 3750) ---

    public function test_per_person_calculates_adult_and_child_pricing(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerPerson);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);
        $option->pricingRules()->create(['participant_type' => 'child', 'price' => 750]);

        $result = $this->service->quote($activity, $option, participants: ['adult' => 2, 'child' => 1]);

        $this->assertEquals(3750.0, $result['total']);
        $this->assertEquals('per_person', $result['pricing_model']);
        $this->assertCount(2, $result['breakdown']);
    }

    public function test_per_person_infant_price_of_zero_is_valid(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerPerson);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);
        $option->pricingRules()->create(['participant_type' => 'infant', 'price' => 0]);

        $result = $this->service->quote($activity, $option, participants: ['adult' => 1, 'infant' => 1]);

        $this->assertEquals(1500.0, $result['total']);
    }

    public function test_per_person_missing_required_participant_price_throws(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerPerson);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);
        // No 'child' rule configured at all.

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option, participants: ['adult' => 1, 'child' => 1]);
    }

    public function test_per_person_with_no_participants_throws(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerPerson);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option, participants: null);
    }

    // --- Per Trip (spec §11, task example: 8000 flat, never multiplied) ---

    public function test_per_trip_charges_flat_amount_regardless_of_participants(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerTrip, ['max_participants' => 8]);
        $option->pricingRules()->create(['price' => 8000]);

        $result = $this->service->quote($activity, $option, participants: ['adult' => 4, 'child' => 2]);

        $this->assertEquals(8000.0, $result['total']);
        $this->assertEquals('trip', $result['breakdown'][0]['type']);
    }

    public function test_per_trip_rejects_participants_over_capacity(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerTrip, ['max_participants' => 6]);
        $option->pricingRules()->create(['price' => 8000]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option, participants: ['adult' => 7]);
    }

    // --- Per Group (spec §12) ---

    public function test_per_group_charges_flat_amount_within_configured_range(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerGroup, ['min_participants' => 2, 'max_participants' => 6]);
        $option->pricingRules()->create(['price' => 5000]);

        $result = $this->service->quote($activity, $option, participants: ['adult' => 4]);

        $this->assertEquals(5000.0, $result['total']);
    }

    public function test_per_group_rejects_participants_below_minimum(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerGroup, ['min_participants' => 2, 'max_participants' => 6]);
        $option->pricingRules()->create(['price' => 5000]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option, participants: ['adult' => 1]);
    }

    // --- Per Hour (spec §13, task example: 4 hours x 5000 = 20000) ---

    public function test_per_hour_multiplies_price_by_duration(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerHour, ['min_duration' => 2, 'max_duration' => 8]);
        $option->pricingRules()->create(['price' => 5000]);

        $result = $this->service->quote($activity, $option, duration: 4);

        $this->assertEquals(20000.0, $result['total']);
        $this->assertEquals('hour', $result['breakdown'][0]['type']);
    }

    public function test_per_hour_rejects_duration_outside_bounds(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerHour, ['min_duration' => 2, 'max_duration' => 8]);
        $option->pricingRules()->create(['price' => 5000]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option, duration: 10);
    }

    public function test_per_hour_without_duration_throws(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerHour);
        $option->pricingRules()->create(['price' => 5000]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option, duration: null);
    }

    // --- Per Day (spec §14) ---

    public function test_per_day_multiplies_price_by_days(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerDay);
        $option->pricingRules()->create(['price' => 25000]);

        $result = $this->service->quote($activity, $option, duration: 2);

        $this->assertEquals(50000.0, $result['total']);
        $this->assertEquals('day', $result['breakdown'][0]['type']);
    }

    // --- Per Unit (spec §15, task example: 2 jet skis x 1000 = 2000) ---

    public function test_per_unit_multiplies_price_by_quantity(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerUnit);
        $option->pricingRules()->create(['price' => 1000]);

        $result = $this->service->quote($activity, $option, quantity: 2);

        $this->assertEquals(2000.0, $result['total']);
        $this->assertEquals('unit', $result['breakdown'][0]['type']);
    }

    public function test_per_unit_rejects_quantity_outside_bounds(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerUnit, ['min_quantity' => 1, 'max_quantity' => 3]);
        $option->pricingRules()->create(['price' => 1000]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option, quantity: 5);
    }

    // --- Fixed (spec §16) ---

    public function test_fixed_charges_the_configured_amount_once(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::Fixed);
        $option->pricingRules()->create(['price' => 3000]);

        $result = $this->service->quote($activity, $option, participants: ['adult' => 5]);

        $this->assertEquals(3000.0, $result['total']);
        $this->assertEquals('fixed', $result['breakdown'][0]['type']);
    }

    // --- Package (spec §17) ---

    public function test_package_charges_the_configured_package_price(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::Package, ['min_duration' => 4, 'max_duration' => 4]);
        $option->pricingRules()->create(['price' => 18000]);

        $result = $this->service->quote($activity, $option);

        $this->assertEquals(18000.0, $result['total']);
        $this->assertEquals('package', $result['breakdown'][0]['type']);
    }

    // --- Schedule-period-specific pricing + resolution priority (spec §18, §22) ---

    public function test_schedule_period_specific_rule_overrides_general_rule(): void
    {
        $activity = $this->makeActivity();
        $morning = $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $evening = $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '17:00', 'end_time' => '21:00']);

        $option = $this->makeOption($activity, BookingModel::PerPerson);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1200]); // general
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500, 'schedule_period_id' => $evening->id]); // evening override

        $morningResult = $this->service->quote($activity, $option, schedulePeriod: $morning, participants: ['adult' => 1]);
        $eveningResult = $this->service->quote($activity, $option, schedulePeriod: $evening, participants: ['adult' => 1]);

        $this->assertEquals(1200.0, $morningResult['total'], 'Morning should use the general rule.');
        $this->assertEquals(1500.0, $eveningResult['total'], 'Evening should use its specific override.');
    }

    public function test_period_general_rule_with_no_participant_type_is_priority_two(): void
    {
        // A per_trip-style rule (participant_type always null) scoped to a
        // specific period should still be found and preferred over the
        // fully-general rule for that same period.
        $activity = $this->makeActivity();
        $period = $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);

        $option = $this->makeOption($activity, BookingModel::PerTrip);
        $option->pricingRules()->create(['price' => 8000]); // fully general
        $option->pricingRules()->create(['price' => 9000, 'schedule_period_id' => $period->id]); // period-specific

        $result = $this->service->quote($activity, $option, schedulePeriod: $period);

        $this->assertEquals(9000.0, $result['total']);
    }

    // --- Cross-cutting guards ---

    public function test_booking_option_from_a_different_activity_is_rejected(): void
    {
        $activityA = $this->makeActivity();
        $activityB = $this->makeActivity();
        $optionOnB = $this->makeOption($activityB, BookingModel::PerTrip);
        $optionOnB->pricingRules()->create(['price' => 8000]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activityA, $optionOnB);
    }

    public function test_inactive_booking_option_is_rejected(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerTrip, ['is_active' => false]);
        $option->pricingRules()->create(['price' => 8000]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option);
    }

    public function test_inactive_pricing_rule_is_never_used(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerTrip);
        $option->pricingRules()->create(['price' => 8000, 'is_active' => false]);

        // No active rule exists at all -> must throw, not silently resolve
        // to the inactive one.
        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option);
    }

    public function test_missing_price_never_silently_creates_a_free_booking(): void
    {
        $activity = $this->makeActivity();
        $option = $this->makeOption($activity, BookingModel::PerTrip);
        // Zero pricing rules configured at all.

        $this->expectException(ValidationException::class);

        $this->service->quote($activity, $option);
    }

    public function test_schedule_period_from_a_different_activity_is_rejected(): void
    {
        $activityA = $this->makeActivity();
        $activityB = $this->makeActivity();
        $periodOnB = $activityB->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);

        $option = $this->makeOption($activityA, BookingModel::PerTrip);
        $option->pricingRules()->create(['price' => 8000]);

        $this->expectException(ValidationException::class);

        $this->service->quote($activityA, $option, schedulePeriod: $periodOnB);
    }
}
