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

class ActivityScheduleInspectionTest extends TestCase
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

    protected function makeActivityWithSchedule(): Activity
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
        ]);
        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $activity->availabilityOverrides()->create(['date_from' => '2026-09-12', 'date_to' => '2026-09-12', 'is_unavailable' => true]);

        return $activity;
    }

    public function test_admin_with_permission_can_view_activity_schedule(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $activity = $this->makeActivityWithSchedule();

        $response = $this->actingAs($admin, 'web')->get(route('admin.activities.show', $activity));

        $response->assertOk()
            ->assertSee('09:00')
            ->assertSee('2026-09-12');
    }

    public function test_admin_without_activities_permission_cannot_view_schedule(): void
    {
        $admin = $this->makeAdmin('support_admin');
        $activity = $this->makeActivityWithSchedule();

        $response = $this->actingAs($admin, 'web')->get(route('admin.activities.show', $activity));

        $response->assertStatus(403);
    }

    public function test_inactive_period_still_visible_to_admin_unlike_public_api(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $activity = $this->makeActivityWithSchedule();
        $activity->schedulePeriods()->first()->update(['status' => 'inactive']);

        $response = $this->actingAs($admin, 'web')->get(route('admin.activities.show', $activity));

        // Admin inspection must show inactive periods too — only the
        // PUBLIC availability endpoint hides them from bookability.
        $response->assertOk()->assertSee('09:00');
    }
}
