<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\ActivityAvailabilityOverride;
use App\Models\ActivitySchedulePeriod;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleModelTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_same_day_period_does_not_cross_midnight(): void
    {
        $this->assertFalse(ActivitySchedulePeriod::determinesCrossesMidnight('09:00:00', '13:00:00'));
    }

    public function test_overnight_period_crosses_midnight(): void
    {
        $this->assertTrue(ActivitySchedulePeriod::determinesCrossesMidnight('17:00:00', '05:00:00'));
    }

    public function test_activity_has_many_schedule_periods_ordered_by_day_and_sort(): void
    {
        $activity = $this->makeActivity();

        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '17:00',
            'end_time' => '05:00',
            'crosses_midnight' => true,
            'sort_order' => 1,
        ]);
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'crosses_midnight' => false,
            'sort_order' => 0,
        ]);
        $activity->schedulePeriods()->create([
            'day_of_week' => 0,
            'start_time' => '10:00',
            'end_time' => '14:00',
            'crosses_midnight' => false,
        ]);

        $periods = $activity->schedulePeriods;

        $this->assertCount(3, $periods);
        $this->assertEquals(0, $periods[0]->day_of_week);
        $this->assertEquals(6, $periods[1]->day_of_week);
        $this->assertEquals('09:00', \Illuminate\Support\Carbon::parse($periods[1]->start_time)->format('H:i'));
        $this->assertEquals(6, $periods[2]->day_of_week);
        $this->assertEquals('17:00', \Illuminate\Support\Carbon::parse($periods[2]->start_time)->format('H:i'));
    }

    public function test_inactive_period_is_flagged_correctly(): void
    {
        $activity = $this->makeActivity();

        $period = $activity->schedulePeriods()->create([
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'status' => 'inactive',
        ]);

        $this->assertFalse($period->isActive());
    }

    public function test_single_date_override_covers_date(): void
    {
        $activity = $this->makeActivity();

        $override = $activity->availabilityOverrides()->create([
            'date_from' => '2026-09-12',
            'date_to' => '2026-09-12',
            'is_unavailable' => true,
        ]);

        $this->assertTrue($override->coversDate(\Illuminate\Support\Carbon::parse('2026-09-12')));
        $this->assertFalse($override->coversDate(\Illuminate\Support\Carbon::parse('2026-09-13')));
    }

    public function test_date_range_override_covers_every_date_in_range(): void
    {
        $activity = $this->makeActivity();

        $override = $activity->availabilityOverrides()->create([
            'date_from' => '2026-09-12',
            'date_to' => '2026-09-20',
            'is_unavailable' => true,
        ]);

        $this->assertTrue($override->coversDate(\Illuminate\Support\Carbon::parse('2026-09-12')));
        $this->assertTrue($override->coversDate(\Illuminate\Support\Carbon::parse('2026-09-15')));
        $this->assertTrue($override->coversDate(\Illuminate\Support\Carbon::parse('2026-09-20')));
        $this->assertFalse($override->coversDate(\Illuminate\Support\Carbon::parse('2026-09-21')));
    }

    public function test_price_override_is_nullable_meaning_inherit(): void
    {
        $activity = $this->makeActivity();

        $override = $activity->availabilityOverrides()->create([
            'date_from' => '2026-09-12',
            'date_to' => '2026-09-12',
            'is_unavailable' => false,
        ]);

        $this->assertNull($override->price);
    }

    public function test_deleting_activity_cascades_to_schedule_periods_and_overrides(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '13:00']);
        $activity->availabilityOverrides()->create(['date_from' => '2026-09-12', 'date_to' => '2026-09-12']);

        $activity->forceDelete();

        $this->assertDatabaseCount('activity_schedule_periods', 0);
        $this->assertDatabaseCount('activity_availability_overrides', 0);
    }
}
