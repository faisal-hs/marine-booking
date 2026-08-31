<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPermissionTest extends TestCase
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

    public function test_super_admin_sees_every_module_in_sidebar(): void
    {
        $admin = $this->makeAdmin('super_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('Users')
            ->assertSee('Roles & Permissions')
            ->assertSee('Settings');
    }

    public function test_content_admin_does_not_see_users_in_sidebar(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('Categories')
            ->assertSee('Activities')
            ->assertDontSee('Roles & Permissions')
            ->assertDontSee('Users');
    }

    public function test_support_admin_sees_users_page_but_cannot_create_or_delete(): void
    {
        $admin = $this->makeAdmin('support_admin');
        $target = User::factory()->customer()->create();

        $response = $this->actingAs($admin, 'web')->get(route('admin.users.index'));

        $response->assertOk()
            ->assertDontSee('+ Add User');

        $showResponse = $this->actingAs($admin, 'web')->get(route('admin.users.show', $target));
        $showResponse->assertOk()->assertDontSee('Delete');
    }

    public function test_admin_role_sees_users_page_with_full_actions(): void
    {
        $admin = $this->makeAdmin('admin');
        $target = User::factory()->customer()->create();

        $indexResponse = $this->actingAs($admin, 'web')->get(route('admin.users.index'));
        $indexResponse->assertOk()
            ->assertSee('+ Add User')
            ->assertSee('Edit')
            ->assertSee('Delete');

        $dashboardResponse = $this->actingAs($admin, 'web')->get(route('admin.dashboard'));
        $dashboardResponse->assertOk()->assertDontSee('Roles & Permissions');
    }

    public function test_content_admin_cannot_access_users_index_directly(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.users.index'));

        $response->assertStatus(403);
    }
}
