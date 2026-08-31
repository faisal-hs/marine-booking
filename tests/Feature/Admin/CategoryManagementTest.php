<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    protected function makeAdmin(string $role): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    public function test_content_admin_can_create_category_with_image(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $image = UploadedFile::fake()->image('diving.jpg');

        $response = $this->actingAs($admin, 'web')->post(route('admin.categories.store'), [
            'name' => 'Deep Sea Diving',
            'status' => 'active',
            'sort_order' => 1,
            'image' => $image,
        ]);

        $response->assertRedirect(route('admin.categories.index'));

        $category = Category::where('name', 'Deep Sea Diving')->first();
        $this->assertNotNull($category);
        $this->assertEquals('deep-sea-diving', $category->slug);
        Storage::disk('public')->assertExists($category->image);
    }

    public function test_duplicate_name_generates_unique_slug(): void
    {
        $admin = $this->makeAdmin('content_admin');
        Category::create(['name' => 'Diving', 'slug' => 'diving']);

        $this->actingAs($admin, 'web')->post(route('admin.categories.store'), [
            'name' => 'Diving',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('categories', ['slug' => 'diving-1']);
    }

    public function test_support_admin_cannot_create_category(): void
    {
        $admin = $this->makeAdmin('support_admin');

        $response = $this->actingAs($admin, 'web')->post(route('admin.categories.store'), [
            'name' => 'Blocked Category',
            'status' => 'active',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('categories', ['name' => 'Blocked Category']);
    }

    public function test_updating_image_deletes_old_file(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $category = Category::create([
            'name' => 'Snorkeling',
            'slug' => 'snorkeling',
            'image' => 'categories/old-image.jpg',
        ]);
        Storage::disk('public')->put('categories/old-image.jpg', 'fake-content');

        $newImage = UploadedFile::fake()->image('new.jpg');

        $this->actingAs($admin, 'web')->put(route('admin.categories.update', $category), [
            'name' => 'Snorkeling',
            'status' => 'active',
            'image' => $newImage,
        ]);

        Storage::disk('public')->assertMissing('categories/old-image.jpg');
        $this->assertNotEquals('categories/old-image.jpg', $category->fresh()->image);
    }

    public function test_deleting_category_removes_image_file(): void
    {
        $admin = $this->makeAdmin('content_admin');
        Storage::disk('public')->put('categories/to-delete.jpg', 'fake-content');
        $category = Category::create([
            'name' => 'Old Category',
            'slug' => 'old-category',
            'image' => 'categories/to-delete.jpg',
        ]);

        $this->actingAs($admin, 'web')->delete(route('admin.categories.destroy', $category));

        Storage::disk('public')->assertMissing('categories/to-delete.jpg');
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }
}
