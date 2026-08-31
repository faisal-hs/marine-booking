<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureDefinitionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(string $role): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    public function test_content_admin_can_create_feature_definition(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        $response = $this->actingAs($admin, 'web')->post(route('admin.feature-definitions.store'), [
            'category_id' => $category->id,
            'label' => 'Dive Depth',
            'key' => 'dive_depth',
            'type' => 'number',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('admin.feature-definitions.index'));
        $this->assertDatabaseHas('feature_definitions', ['category_id' => $category->id, 'key' => 'dive_depth']);
    }

    public function test_duplicate_key_within_same_category_is_rejected(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        FeatureDefinition::create(['category_id' => $category->id, 'key' => 'dive_depth', 'label' => 'Existing', 'type' => 'number']);

        $response = $this->actingAs($admin, 'web')->post(route('admin.feature-definitions.store'), [
            'category_id' => $category->id,
            'label' => 'Dive Depth Again',
            'key' => 'dive_depth',
            'type' => 'number',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('key');
        $this->assertEquals(1, FeatureDefinition::where('category_id', $category->id)->where('key', 'dive_depth')->count());
    }

    public function test_same_key_is_allowed_across_different_categories(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $categoryA = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $categoryB = Category::create(['name' => 'Snorkeling', 'slug' => 'snorkeling-' . uniqid(), 'status' => 'active']);
        FeatureDefinition::create(['category_id' => $categoryA->id, 'key' => 'equipment_included', 'label' => 'Equipment', 'type' => 'boolean']);

        $response = $this->actingAs($admin, 'web')->post(route('admin.feature-definitions.store'), [
            'category_id' => $categoryB->id,
            'label' => 'Equipment',
            'key' => 'equipment_included',
            'type' => 'boolean',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('admin.feature-definitions.index'));
        $this->assertDatabaseHas('feature_definitions', ['category_id' => $categoryB->id, 'key' => 'equipment_included']);
    }

    public function test_select_type_requires_options(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        $response = $this->actingAs($admin, 'web')->post(route('admin.feature-definitions.store'), [
            'category_id' => $category->id,
            'label' => 'Boat Type',
            'key' => 'boat_type',
            'type' => 'select',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('options');
    }

    public function test_support_admin_cannot_manage_feature_definitions(): void
    {
        $admin = $this->makeAdmin('support_admin');
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        $response = $this->actingAs($admin, 'web')->post(route('admin.feature-definitions.store'), [
            'category_id' => $category->id,
            'label' => 'Dive Depth',
            'key' => 'dive_depth',
            'type' => 'number',
            'status' => 'active',
        ]);

        $response->assertStatus(403);
    }

    public function test_soft_deleting_feature_definition_preserves_historical_values(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $definition = FeatureDefinition::create(['category_id' => $category->id, 'key' => 'dive_depth', 'label' => 'Dive Depth', 'type' => 'number']);

        $response = $this->actingAs($admin, 'web')->delete(route('admin.feature-definitions.destroy', $definition));

        $response->assertRedirect(route('admin.feature-definitions.index'));
        $this->assertSoftDeleted('feature_definitions', ['id' => $definition->id]);
    }
}
