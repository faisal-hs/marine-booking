<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Module 08 note: pricing assertions were removed from every test here —
 * AvailabilityService no longer resolves price at all (that moved to
 * ActivityBookingOption/ActivityPricingRule via ActivityPricingService,
 * kept deliberately separate per the "availability and pricing must
 * remain independent services" architectural rule). Two tests whose
 * entire premise was schedule-period-price/override-price resolution
 * were removed outright rather than patched into something they no
 * longer test; instructions-override inheritance (a genuine remaining
 * availability concern) is preserved in test_override_without_instructions_uses_weekly_period.
 */
class AvailabilityResolverTest extends TestCase
{
    use RefreshDatabase;

    protected AvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AvailabilityService::class);
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

    public function test_same_day_period_resolves_correctly(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'crosses_midnight' => false,
        ]);

        $result = $this->service->resolveForDate($activity->id, Carbon::parse('2026-09-05'));

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('13:00', $result[0]['end']);
        $this->assertFalse($result[0]['next_day']);
    }

    public function test_overnight_period_resolves_with_next_day_flag(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '17:00',
            'end_time' => '05:00',
            'crosses_midnight' => true,
        ]);

        $result = $this->service->resolveForDate($activity->id, Carbon::parse('2026-09-05'));

        $this->assertCount(1, $result);
        $this->assertEquals('17:00', $result[0]['start']);
        $this->assertEquals('05:00', $result[0]['end']);
        $this->assertTrue($result[0]['next_day']);
    }

    public function test_overnight_period_does_not_duplicate_onto_next_calendar_day(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '17:00',
            'end_time' => '05:00',
            'crosses_midnight' => true,
        ]);

        $sundayResult = $this->service->resolveForDate($activity->id, Carbon::parse('2026-09-06'));

        $this->assertCount(0, $sundayResult);
    }

    public function test_multiple_periods_per_day_all_resolve(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'sort_order' => 0,
        ]);
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '17:00',
            'end_time' => '05:00',
            'crosses_midnight' => true,
            'sort_order' => 1,
        ]);

        $result = $this->service->resolveForDate($activity->id, Carbon::parse('2026-09-05'));

        $this->assertCount(2, $result);
    }

    public function test_inactive_period_is_excluded_from_resolution(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'status' => 'inactive',
        ]);

        $result = $this->service->resolveForDate($activity->id, Carbon::parse('2026-09-05'));

        $this->assertCount(0, $result);
    }

    public function test_blackout_override_removes_all_periods_for_that_date(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);
        $activity->availabilityOverrides()->create([
            'date_from' => '2026-09-05',
            'date_to' => '2026-09-05',
            'is_unavailable' => true,
        ]);

        $result = $this->service->resolveForDate($activity->id, Carbon::parse('2026-09-05'));

        $this->assertCount(0, $result);
    }

    public function test_date_range_blackout_removes_periods_across_the_whole_range(): void
    {
        $activity = $this->makeActivity();
        for ($d = 0; $d <= 6; $d++) {
            $activity->schedulePeriods()->create([
                'day_of_week' => $d,
                'start_time' => '09:00',
                'end_time' => '13:00',
            ]);
        }
        $activity->availabilityOverrides()->create([
            'date_from' => '2026-09-12',
            'date_to' => '2026-09-20',
            'is_unavailable' => true,
        ]);

        $result = $this->service->resolveForRange($activity->id, Carbon::parse('2026-09-12'), Carbon::parse('2026-09-20'));

        foreach ($result as $date => $periods) {
            $this->assertCount(0, $periods, "Expected {$date} to be blacked out.");
        }
    }

    public function test_override_without_instructions_uses_weekly_period_instructions(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'instructions' => 'Weekly default instructions.',
        ]);
        // A non-blackout override present on this date, but with no
        // instructions of its own — the weekly period's instructions
        // must still surface, not be blanked out by the override's presence.
        $activity->availabilityOverrides()->create([
            'date_from' => '2026-09-05',
            'date_to' => '2026-09-05',
            'is_unavailable' => false,
        ]);

        $result = $this->service->resolveForDate($activity->id, Carbon::parse('2026-09-05'));

        $this->assertEquals('Weekly default instructions.', $result[0]['instructions']);
    }

    public function test_override_instructions_take_precedence_over_weekly_period(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'instructions' => 'Weekly default instructions.',
        ]);
        $activity->availabilityOverrides()->create([
            'date_from' => '2026-09-05',
            'date_to' => '2026-09-05',
            'is_unavailable' => false,
            'instructions' => 'Bring extra sunscreen.',
        ]);

        $result = $this->service->resolveForDate($activity->id, Carbon::parse('2026-09-05'));

        $this->assertEquals('Bring extra sunscreen.', $result[0]['instructions']);
    }

    public function test_monthly_resolution_returns_every_day_keyed_by_date(): void
    {
        $activity = $this->makeActivity();
        $activity->schedulePeriods()->create([
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);

        $result = $this->service->resolveForMonth($activity->id, 2026, 9);

        $this->assertCount(30, $result);
        $this->assertArrayHasKey('2026-09-01', $result);
        $this->assertArrayHasKey('2026-09-30', $result);
    }

    public function test_range_resolution_rejects_to_date_before_from_date(): void
    {
        $activity = $this->makeActivity();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->service->resolveForRange($activity->id, Carbon::parse('2026-09-20'), Carbon::parse('2026-09-12'));
    }
}
