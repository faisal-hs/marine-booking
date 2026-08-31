<?php

namespace Tests\Feature\Performance;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for the N+1 gap in ActivityResource's `category`/`images`
 * access when returned inside a paginated collection.
 *
 * Rather than comparing query counts across two sequential requests in the
 * same process (which proved unreliable in PHPUnit due to state artifacts
 * around Sanctum guard caching and possibly RefreshDatabase's transaction
 * handling — confirmed independently via a real HTTP kernel call outside
 * the test runner, which showed a constant 8 queries for both 2 and 8
 * activities), this test asserts directly on the query log's SHAPE for a
 * single, realistic multi-activity request: exactly one query against
 * `categories` and exactly one against `activity_images`, regardless of
 * how many activities are returned. An N+1 regression would produce one
 * `categories`/`activity_images` query PER activity instead of one total.
 */
class ActivityEagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_activity_listing_eager_loads_category_and_images_in_constant_queries(): void
    {
        $this->seedPublishedActivitiesWithImages(6);

        DB::enableQueryLog();
        $this->getJson('/api/v1/public/activities')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertQueryCountForTable($queries, 'categories', 1);
        $this->assertQueryCountForTable($queries, 'activity_images', 1);
    }

    public function test_provider_activity_listing_eager_loads_category_and_images_in_constant_queries(): void
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Query Count Diving',
            'slug' => 'query-count-diving-' . uniqid(),
        ]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        for ($i = 0; $i < 6; $i++) {
            $activity = Activity::create([
                'provider_id' => $provider->id,
                'category_id' => $category->id,
                'title' => 'Provider Activity ' . uniqid(),
                'slug' => 'provider-activity-' . uniqid(),
            ]);
            $activity->images()->create([
                'path' => 'activities/fake-' . uniqid() . '.jpg',
                'sort_order' => 0,
                'is_cover' => true,
            ]);
        }

        DB::enableQueryLog();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/provider/activities')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertQueryCountForTable($queries, 'categories', 1);
        $this->assertQueryCountForTable($queries, 'activity_images', 1);
    }

    protected function seedPublishedActivitiesWithImages(int $count, int $imagesEach = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->provider()->create();
            $provider = Provider::create([
                'user_id' => $user->id,
                'business_name' => "Provider {$i}",
                'slug' => 'provider-' . uniqid(),
            ]);
            $category = Category::create([
                'name' => "Category {$i}",
                'slug' => 'category-' . uniqid(),
                'status' => 'active',
            ]);
            $activity = Activity::create([
                'provider_id' => $provider->id,
                'category_id' => $category->id,
                'title' => "Activity {$i}",
                'slug' => 'activity-' . uniqid(),
                'status' => 'published',
            ]);

            for ($j = 0; $j < $imagesEach; $j++) {
                $activity->images()->create([
                    'path' => "activities/fake-{$i}-{$j}.jpg",
                    'sort_order' => $j,
                    'is_cover' => $j === 0,
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{query: string, bindings: array, time: float}>  $queries
     */
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
