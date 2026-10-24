<?php

namespace Tests\Feature\Seeders;

use App\Models\Activity;
use App\Models\ActivityAvailabilityOverride;
use App\Services\AvailabilityService;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\FeatureDefinitionSeeder;
use Database\Seeders\ProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AvailabilitySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function seedFullChain(): void
    {
        $this->seed(ProviderSeeder::class);
        $this->seed(CustomerSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FeatureDefinitionSeeder::class);
        $this->seed(ActivitySeeder::class);
    }

    public function test_overrides_exist_but_not_for_every_activity(): void
    {
        $this->seedFullChain();

        $totalActivities = Activity::count();
        $activitiesWithOverrides = Activity::has('availabilityOverrides')->count();

        $this->assertGreaterThan(0, $activitiesWithOverrides);
        $this->assertLessThan($totalActivities, $activitiesWithOverrides);
    }

    public function test_both_blackout_and_price_overrides_exist(): void
    {
        $this->seedFullChain();

        $this->assertGreaterThan(0, ActivityAvailabilityOverride::where('is_unavailable', true)->count());
        $this->assertGreaterThan(0, ActivityAvailabilityOverride::where('is_unavailable', false)->count());
    }

    public function test_both_single_date_and_date_range_overrides_exist(): void
    {
        $this->seedFullChain();

        $singleDate = ActivityAvailabilityOverride::whereColumn('date_from', 'date_to')->count();
        $dateRange = ActivityAvailabilityOverride::whereColumn('date_from', '!=', 'date_to')->count();

        $this->assertGreaterThan(0, $singleDate);
        $this->assertGreaterThan(0, $dateRange);
    }

    public function test_resolver_returns_the_correct_period_when_no_blackout_applies(): void
    {
        $this->seedFullChain();

        $activity = Activity::where('status', 'published')->has('schedulePeriods')->first();
        $this->assertNotNull($activity);

        // A date far outside any seeded override window (which spans
        // roughly now()+30 .. now()+47 days — see ActivitySeeder::
        // attachOverrides()), on a weekday the activity has a period for.
        $date = now()->startOfDay();
        $weekday = $date->dayOfWeek;
        $period = $activity->schedulePeriods()->where('day_of_week', $weekday)->where('status', 'active')->first();

        if (! $period) {
            $this->markTestSkipped('No active period on the sampled weekday for this activity.');
        }

        $result = app(AvailabilityService::class)->resolveForDate($activity->id, $date);

        $this->assertNotEmpty($result);
        $matching = collect($result)->firstWhere('start', Carbon::parse($period->start_time)->format('H:i'));
        $this->assertNotNull($matching);
        $this->assertEquals(Carbon::parse($period->end_time)->format('H:i'), $matching['end']);
    }

    public function test_blackout_override_removes_periods_on_seeded_published_activity(): void
    {
        $this->seedFullChain();

        // planIndex 10 (the third 'published' slot in the status plan)
        // deterministically receives override pattern 10 % 4 = 2: a
        // single-day blackout at the seeder's anchor +12 days (now()+30, then
        // +12 for pattern 2) — see ActivitySeeder::attachOverrides().
        $activity = Activity::where('slug', 'like', '%-11')
            ->where('status', 'published')
            ->has('availabilityOverrides')
            ->first();

        $this->assertNotNull($activity, 'Expected to find a published activity at planIndex 10 (slug suffix -11) with a seeded override.');

        $blackoutDate = now()->addDays(30)->addDays(12)->startOfDay();

        $result = app(AvailabilityService::class)->resolveForDate($activity->id, $blackoutDate);

        $this->assertEmpty($result, 'Blackout date should resolve to zero bookable periods.');
    }

    public function test_public_availability_api_returns_meaningful_data_for_a_seeded_published_activity(): void
    {
        $this->seedFullChain();

        $activity = Activity::where('status', 'published')->has('schedulePeriods')->first();
        $this->assertNotNull($activity);

        $from = now()->addDays(5);
        $to = now()->addDays(20);
        $month = now()->addMonthNoOverflow();

        $dateResponse = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?date={$from->toDateString()}");
        $dateResponse->assertOk();

        $rangeResponse = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?from_date={$from->toDateString()}&to_date={$to->toDateString()}");
        $rangeResponse->assertOk();

        $monthResponse = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?month={$month->format('Y-m')}");
        $monthResponse->assertOk()->assertJsonCount($month->daysInMonth, 'data');
    }

    public function test_non_published_activities_are_not_publicly_visible(): void
    {
        $this->seedFullChain();

        foreach (['draft', 'pending_review', 'approved', 'rejected', 'inactive'] as $status) {
            $activity = Activity::where('status', $status)->first();

            if (! $activity) {
                continue;
            }

            $response = $this->getJson("/api/v1/public/activities/{$activity->slug}");
            $response->assertStatus(404);
        }
    }
}
