<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
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

    public function test_super_admin_can_view_users_list(): void
    {
        $admin = $this->makeAdmin('super_admin');
        User::factory()->customer()->count(3)->create();

        $response = $this->actingAs($admin, 'web')->get(route('admin.users.index'));

        $response->assertOk();
    }

    public function test_content_admin_cannot_access_users_list(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.users.index'));

        $response->assertStatus(403);
    }

    public function test_super_admin_can_create_a_staff_member(): void
    {
        $admin = $this->makeAdmin('super_admin');

        $response = $this->actingAs($admin, 'web')->post(route('admin.users.store'), [
            'name' => 'New Staff',
            'email' => 'new.staff@marine-booking.test',
            'password' => 'a-strong-password',
            'role' => 'support_admin',
            'job_title' => 'Support Agent',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'new.staff@marine-booking.test', 'user_type' => 'admin']);
        $this->assertDatabaseHas('admins', ['job_title' => 'Support Agent']);
        $this->assertTrue(User::where('email', 'new.staff@marine-booking.test')->first()->hasRole('support_admin'));
    }

    public function test_support_admin_cannot_create_users(): void
    {
        $admin = $this->makeAdmin('support_admin');

        $response = $this->actingAs($admin, 'web')->post(route('admin.users.store'), [
            'user_type' => 'customer',
            'name' => 'Blocked',
            'phone' => '0599999922',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['phone' => '0599999922']);
    }

    public function test_admin_can_suspend_a_staff_member_and_tokens_are_revoked(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $staff = $this->makeAdmin('support_admin');
        $staff->createToken('test', ['admin']);

        $this->actingAs($admin, 'web')->put(route('admin.users.update', $staff), [
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('users', ['id' => $staff->id, 'status' => 'suspended']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->makeAdmin('super_admin');

        $response = $this->actingAs($admin, 'web')->delete(route('admin.users.destroy', $admin));

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_admin_can_delete_another_staff_member_soft_delete(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $staff = $this->makeAdmin('support_admin');

        $response = $this->actingAs($admin, 'web')->delete(route('admin.users.destroy', $staff));

        $response->assertRedirect(route('admin.users.index'));
        $this->assertSoftDeleted('users', ['id' => $staff->id]);
    }

    public function test_content_admin_cannot_delete_users(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $staff = $this->makeAdmin('support_admin');

        $response = $this->actingAs($admin, 'web')->delete(route('admin.users.destroy', $staff));

        $response->assertStatus(403);
    }
}
