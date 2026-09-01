<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityModerationTest extends TestCase
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

    protected function makePendingActivity(): Activity
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        return Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'pending_review',
        ]);
    }

    public function test_admin_can_approve_pending_activity(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $activity = $this->makePendingActivity();

        $response = $this->actingAs($admin, 'web')->post(route('admin.activities.approve', $activity));

        $response->assertRedirect();
        $this->assertEquals('published', $activity->fresh()->status);
        $this->assertNotNull($activity->fresh()->published_at);
    }

    public function test_admin_can_reject_pending_activity_with_reason(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $activity = $this->makePendingActivity();

        $response = $this->actingAs($admin, 'web')->post(route('admin.activities.reject', $activity), [
            'rejection_reason' => 'Missing safety certification details.',
        ]);

        $response->assertRedirect();
        $activity->refresh();
        $this->assertEquals('rejected', $activity->status);
        $this->assertEquals('Missing safety certification details.', $activity->rejection_reason);
    }

    public function test_reject_requires_a_reason(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $activity = $this->makePendingActivity();

        $response = $this->actingAs($admin, 'web')->post(route('admin.activities.reject', $activity), [
            'rejection_reason' => '',
        ]);

        $response->assertSessionHasErrors('rejection_reason');
        $this->assertEquals('pending_review', $activity->fresh()->status);
    }

    public function test_cannot_approve_an_activity_that_is_not_pending_review(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $activity = $this->makePendingActivity();
        $activity->update(['status' => 'draft']);

        $response = $this->actingAs($admin, 'web')->post(route('admin.activities.approve', $activity));

        $response->assertSessionHasErrors('status');
        $this->assertEquals('draft', $activity->fresh()->status);
    }

    public function test_admin_can_deactivate_and_republish(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $activity = $this->makePendingActivity();
        $activity->update(['status' => 'published', 'published_at' => now()->subDay()]);
        $originalPublishedAt = $activity->published_at;

        $this->actingAs($admin, 'web')->post(route('admin.activities.deactivate', $activity));
        $this->assertEquals('inactive', $activity->fresh()->status);

        $this->actingAs($admin, 'web')->post(route('admin.activities.publish', $activity));
        $activity->refresh();

        $this->assertEquals('published', $activity->status);
        $this->assertEquals(
            $originalPublishedAt->toDateTimeString(),
            $activity->published_at->toDateTimeString()
        ); // republishing must NOT reset the original published_at
    }

    public function test_content_admin_without_moderation_permissions_cannot_approve(): void
    {
        $admin = $this->makeAdmin('content_admin'); // has activities.* CRUD but not approve/reject/publish
        $activity = $this->makePendingActivity();

        $response = $this->actingAs($admin, 'web')->post(route('admin.activities.approve', $activity));

        $response->assertStatus(403);
    }

    public function test_support_admin_cannot_access_activities_queue(): void
    {
        $admin = $this->makeAdmin('support_admin'); // has no activities.* permissions at all

        $response = $this->actingAs($admin, 'web')->get(route('admin.activities.index'));

        $response->assertStatus(403);
    }
}
