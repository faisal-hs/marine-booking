<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * A deleted row is only soft-deleted, so it still owns its slug: the unique index on the column
 * counts it. The checks that pick a free slug used to ignore deleted rows, so deleting an activity,
 * a booking option or a category and then creating another with the same name picked the deleted
 * row's slug and failed with a raw SQL "unique constraint" error (HTTP 500).
 */
class SlugsSurviveDeletionTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_an_activity_can_be_recreated_with_the_title_of_a_deleted_one(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $category = $this->makeCategory();
        $payload = ['category_id' => $category->id, 'title' => 'Slug Probe Trip'];

        $first = $this->asProvider($token)->postJson('/api/v1/provider/activities', $payload)->assertCreated();
        $this->asProvider($token)->deleteJson('/api/v1/provider/activities/'.$first->json('data.uuid'))->assertOk();

        $second = $this->asProvider($token)->postJson('/api/v1/provider/activities', $payload)->assertCreated();

        $this->assertSame('slug-probe-trip', $first->json('data.slug'));
        $this->assertSame('slug-probe-trip-1', $second->json('data.slug'), 'The deleted activity keeps its slug, so the new one gets the next free one.');
    }

    public function test_an_activity_can_be_renamed_to_the_title_of_a_deleted_one(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $gone = $this->makeActivity($provider, 'draft', ['title' => 'Alpha Trip', 'slug' => 'alpha-trip']);
        $gone->delete();
        $other = $this->makeActivity($provider, 'draft', ['title' => 'Beta Trip']);

        $response = $this->asProvider($token)->putJson('/api/v1/provider/activities/'.$other->uuid, ['title' => 'Alpha Trip'])->assertOk();

        $this->assertSame('alpha-trip-1', $response->json('data.slug'));
    }

    public function test_staff_can_create_an_activity_with_the_title_of_a_deleted_one_and_restore_the_old_one(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $category = $this->makeCategory();
        $old = $this->makeActivity($provider, 'draft', ['title' => 'Reef Day', 'category' => $category]);
        $oldSlug = $old->slug;
        $old->delete();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.activities.store'), [
            'provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'Reef Day', 'min_participants' => 1,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin, 'web')->post(route('admin.activities.restore', $old))->assertSessionHasNoErrors();

        $this->assertSame(2, Activity::where('title', 'Reef Day')->count(), 'Both exist now, with different slugs.');
        $this->assertSame($oldSlug, $old->fresh()->slug, 'Restoring does not rename it.');
        $this->assertSame(2, Activity::pluck('slug')->unique()->count());
    }

    public function test_a_booking_option_can_be_recreated_with_the_name_of_a_deleted_one(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');
        $body = ['name' => 'Same Name', 'booking_model' => 'per_person'];
        $url = "/api/v1/provider/activities/{$activity->uuid}/booking-options";

        $first = $this->asProvider($token)->postJson($url, $body)->assertCreated();
        $this->asProvider($token)->deleteJson("{$url}/".$first->json('data.uuid'))->assertOk();

        $second = $this->asProvider($token)->postJson($url, $body)->assertCreated();

        $this->assertSame('same-name', $first->json('data.slug'));
        $this->assertSame('same-name-1', $second->json('data.slug'));
    }

    public function test_a_category_can_be_recreated_with_the_name_of_a_deleted_one(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->post(route('admin.categories.store'), ['name' => 'Slug Probe Category', 'status' => 'active'])->assertSessionHasNoErrors();
        $first = Category::where('name', 'Slug Probe Category')->firstOrFail();
        $this->actingAs($admin, 'web')->delete(route('admin.categories.destroy', $first))->assertSessionHasNoErrors();

        $this->actingAs($admin, 'web')->post(route('admin.categories.store'), ['name' => 'Slug Probe Category', 'status' => 'active'])->assertSessionHasNoErrors();

        $this->assertSame(1, Category::where('name', 'Slug Probe Category')->count(), 'Exactly one is live.');
        $this->assertSame(2, Category::withTrashed()->where('name', 'Slug Probe Category')->count());
        $this->assertSame(2, Category::withTrashed()->where('name', 'Slug Probe Category')->pluck('slug')->unique()->count());
    }

    public function test_a_provider_business_name_can_be_reused_after_a_provider_row_is_deleted(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $provider->update(['business_name' => 'Blue Hole Divers', 'slug' => 'blue-hole-divers']);
        $provider->delete();

        $this->actingAs($this->makeAdmin(), 'web')->post(route('admin.providers.store'), [
            'name' => 'New Owner', 'phone' => '0555123123', 'business_name' => 'Blue Hole Divers',
        ])->assertSessionHasNoErrors();

        $this->assertSame('blue-hole-divers-1', Provider::where('business_name', 'Blue Hole Divers')->orderByDesc('id')->value('slug'));
    }
}
