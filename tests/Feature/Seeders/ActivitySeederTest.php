<?php

namespace Tests\Feature\Seeders;

use App\Models\Activity;
use App\Models\Category;
use App\Models\FeatureDefinition;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\FeatureDefinitionSeeder;
use Database\Seeders\ProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivitySeederTest extends TestCase
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

    public function test_seeds_at_least_110_activities(): void
    {
        $this->seedFullChain();

        $this->assertGreaterThanOrEqual(110, Activity::count());
    }

    public function test_every_category_has_more_than_10_activities(): void
    {
        $this->seedFullChain();

        foreach (Category::all() as $category) {
            $count = Activity::where('category_id', $category->id)->count();

            $this->assertGreaterThan(10, $count, "Category '{$category->name}' has only {$count} activities.");
        }
    }

    public function test_all_lifecycle_statuses_are_represented(): void
    {
        $this->seedFullChain();

        $statuses = Activity::distinct()->pluck('status')->all();

        foreach (['draft', 'approved', 'published', 'inactive'] as $expected) {
            $this->assertContains($expected, $statuses, "Status '{$expected}' has no seeded activities.");
        }

        $this->assertGreaterThan(0, Activity::whereNotNull('suspended_at')->count(), 'Some seeded activities are suspended by an admin.');
        $this->assertNotContains('pending_review', $statuses, 'Nobody approves activities, so nothing waits for approval.');
        $this->assertNotContains('rejected', $statuses);
    }

    public function test_activities_are_distributed_across_multiple_providers_not_one(): void
    {
        $this->seedFullChain();

        $distinctProviders = Activity::distinct()->pluck('provider_id')->count();

        $this->assertGreaterThan(5, $distinctProviders);
    }

    public function test_suspended_activities_say_why_and_were_live_before(): void
    {
        $this->seedFullChain();

        $suspended = Activity::whereNotNull('suspended_at')->get();

        $this->assertGreaterThan(0, $suspended->count());

        foreach ($suspended as $activity) {
            $this->assertSame('inactive', $activity->status, 'A suspension is recorded apart from the status: the activity is off the site');
            $this->assertNotNull($activity->suspension_reason);
            $this->assertSame('published', $activity->suspended_from_status);
            $this->assertNotNull($activity->published_at, 'It was live before it was suspended');
        }
    }

    public function test_published_and_inactive_activities_have_published_at_set(): void
    {
        $this->seedFullChain();

        foreach (Activity::whereIn('status', ['published', 'inactive'])->get() as $activity) {
            $this->assertNotNull($activity->published_at);
        }
    }

    public function test_published_activities_have_images_with_exactly_one_cover(): void
    {
        $this->seedFullChain();

        $published = Activity::where('status', 'published')->with('images')->get();
        $this->assertGreaterThan(0, $published->count());

        foreach ($published as $activity) {
            $this->assertGreaterThanOrEqual(2, $activity->images->count());
            $this->assertEquals(1, $activity->images->where('is_cover', true)->count());
        }
    }

    public function test_activities_have_feature_values_belonging_only_to_their_own_category(): void
    {
        $this->seedFullChain();

        $activities = Activity::with('featureValues.featureDefinition')->has('featureValues')->get();
        $this->assertGreaterThan(0, $activities->count());

        foreach ($activities as $activity) {
            foreach ($activity->featureValues as $value) {
                $this->assertEquals(
                    $activity->category_id,
                    $value->featureDefinition->category_id,
                    'A feature value belongs to a definition from a different category than the activity.'
                );
            }
        }
    }

    public function test_select_type_feature_values_only_use_valid_option_keys(): void
    {
        $this->seedFullChain();

        $selectDefinitions = FeatureDefinition::where('type', 'select')->get();
        $this->assertGreaterThan(0, $selectDefinitions->count());

        foreach ($selectDefinitions as $definition) {
            $validKeys = collect($definition->options)->pluck('key')->all();

            foreach ($definition->values as $value) {
                $this->assertContains($value->value_text, $validKeys);
            }
        }
    }

    public function test_activities_have_options_requirements_inclusions_and_exclusions(): void
    {
        $this->seedFullChain();

        $activity = Activity::has('options')->has('requirements')->first();

        $this->assertNotNull($activity, 'No activity found with both options and requirements.');
        $this->assertGreaterThanOrEqual(2, $activity->options()->count());
        $this->assertGreaterThanOrEqual(2, $activity->requirements()->count());
        $this->assertGreaterThanOrEqual(1, $activity->inclusions()->where('type', 'included')->count());
        $this->assertGreaterThanOrEqual(1, $activity->inclusions()->where('type', 'excluded')->count());
    }

    public function test_running_seeder_twice_does_not_duplicate_activities(): void
    {
        $this->seedFullChain();
        $firstCount = Activity::count();

        $this->seed(ActivitySeeder::class);
        $secondCount = Activity::count();

        $this->assertEquals($firstCount, $secondCount);
    }
}
