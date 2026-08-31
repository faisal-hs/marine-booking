<?php

namespace Tests\Feature\Performance;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActivityDetailsEagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_activity_listing_eager_loads_options_requirements_inclusions_in_constant_queries(): void
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Diving Co', 'slug' => 'diving-co-' . uniqid()]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        for ($i = 0; $i < 6; $i++) {
            $activity = Activity::create([
                'provider_id' => $provider->id,
                'category_id' => $category->id,
                'title' => 'Activity ' . uniqid(),
                'slug' => 'activity-' . uniqid(),
            ]);
            $activity->options()->create(['name' => 'Option ' . $i]);
            $activity->requirements()->create(['text' => 'Requirement ' . $i]);
            $activity->inclusions()->create(['type' => 'included', 'description' => 'Item ' . $i]);
        }

        DB::enableQueryLog();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/provider/activities')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertQueryCountForTable($queries, 'activity_options', 1);
        $this->assertQueryCountForTable($queries, 'activity_requirements', 1);
        $this->assertQueryCountForTable($queries, 'activity_inclusions', 1);
    }

    protected function assertQueryCountForTable(array $queries, string $table, int $expected): void
    {
        $pattern = '/from\s+[`"]?' . preg_quote($table, '/') . '[`"]?\b/i';
        $matching = array_filter($queries, fn($q) => preg_match($pattern, $q['query']) === 1);

        $this->assertCount(
            $expected,
            $matching,
            "Expected {$expected} quer" . ($expected === 1 ? 'y' : 'ies') . " against `{$table}`, found " . count($matching) .
                '. Full query log: ' . PHP_EOL . implode(PHP_EOL, array_column($queries, 'query'))
        );
    }
}
