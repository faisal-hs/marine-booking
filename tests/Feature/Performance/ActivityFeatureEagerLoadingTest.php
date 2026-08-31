<?php

namespace Tests\Feature\Performance;

use App\Models\Activity;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mirrors ActivityEagerLoadingTest's driver-agnostic query-shape approach
 * (asserting exact query counts per table rather than comparing sequential
 * request counts, which proved unreliable due to Sanctum guard caching).
 */
class ActivityFeatureEagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_activity_listing_eager_loads_feature_values_in_constant_queries(): void
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Diving Co', 'slug' => 'diving-co-' . uniqid()]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $depth = FeatureDefinition::create(['category_id' => $category->id, 'key' => 'dive_depth', 'label' => 'Dive Depth', 'type' => 'number']);

        for ($i = 0; $i < 6; $i++) {
            $activity = Activity::create([
                'provider_id' => $provider->id,
                'category_id' => $category->id,
                'title' => 'Activity ' . uniqid(),
                'slug' => 'activity-' . uniqid(),
            ]);
            $activity->featureValues()->create([
                'feature_definition_id' => $depth->id,
                'value_number' => 10 + $i,
            ]);
        }

        DB::enableQueryLog();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/provider/activities')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertQueryCountForTable($queries, 'activity_feature_values', 1);
        $this->assertQueryCountForTable($queries, 'feature_definitions', 1);
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
