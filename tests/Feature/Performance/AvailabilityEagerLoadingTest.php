<?php

namespace Tests\Feature\Performance;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvailabilityEagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_resolution_executes_exactly_two_queries_regardless_of_days_in_range(): void
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Diving Co', 'slug' => 'diving-co-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
        ]);

        for ($d = 0; $d <= 6; $d++) {
            $activity->schedulePeriods()->create(['day_of_week' => $d, 'start_time' => '09:00', 'end_time' => '13:00']);
        }
        $activity->availabilityOverrides()->create(['date_from' => '2026-09-12', 'date_to' => '2026-09-15', 'price' => 350]);

        DB::enableQueryLog();
        app(AvailabilityService::class)->resolveForMonth($activity->id, 2026, 9); // 30 days
        $monthQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        app(AvailabilityService::class)->resolveForRange($activity->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-02')); // 2 days
        $rangeQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEquals(2, $monthQueries, 'Expected exactly 2 queries (periods + overrides) for a 30-day month.');
        $this->assertEquals(2, $rangeQueries, 'Expected exactly 2 queries (periods + overrides) for a 2-day range.');
    }
}
