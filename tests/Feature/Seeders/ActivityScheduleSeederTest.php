<?php

namespace Tests\Feature\Seeders;

use App\Models\Activity;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\FeatureDefinitionSeeder;
use Database\Seeders\ProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityScheduleSeederTest extends TestCase
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

    public function test_every_activity_has_schedule_periods_covering_all_7_weekdays(): void
    {
        $this->seedFullChain();

        $activity = Activity::has('schedulePeriods')->first();
        $this->assertNotNull($activity);

        $daysCovered = $activity->schedulePeriods()->distinct()->pluck('day_of_week')->sort()->values()->all();

        $this->assertEquals([0, 1, 2, 3, 4, 5, 6], $daysCovered);
    }

    public function test_every_scheduled_day_has_more_than_one_period(): void
    {
        $this->seedFullChain();

        $activity = Activity::has('schedulePeriods')->first();

        for ($day = 0; $day <= 6; $day++) {
            $count = $activity->schedulePeriods()->where('day_of_week', $day)->count();

            $this->assertGreaterThan(1, $count, "Day {$day} has only {$count} period(s).");
        }
    }

    public function test_some_periods_cross_midnight(): void
    {
        $this->seedFullChain();

        $overnightCount = \App\Models\ActivitySchedulePeriod::where('crosses_midnight', true)->count();

        $this->assertGreaterThan(0, $overnightCount);
    }

    public function test_overnight_period_start_time_is_after_end_time(): void
    {
        $this->seedFullChain();

        $overnightPeriods = \App\Models\ActivitySchedulePeriod::where('crosses_midnight', true)->limit(20)->get();
        $this->assertGreaterThan(0, $overnightPeriods->count());

        foreach ($overnightPeriods as $period) {
            $this->assertGreaterThan($period->end_time, $period->start_time);
        }
    }

    public function test_some_periods_are_inactive(): void
    {
        $this->seedFullChain();

        $inactiveCount = \App\Models\ActivitySchedulePeriod::where('status', 'inactive')->count();
        $activeCount = \App\Models\ActivitySchedulePeriod::where('status', 'active')->count();

        $this->assertGreaterThan(0, $inactiveCount);
        $this->assertGreaterThan($inactiveCount, $activeCount, 'Most periods should remain active, not inactive.');
    }

    public function test_schedule_periods_have_instructions_populated(): void
    {
        $this->seedFullChain();

        $withInstructions = \App\Models\ActivitySchedulePeriod::whereNotNull('instructions')->count();

        $this->assertGreaterThan(0, $withInstructions);
    }
}
