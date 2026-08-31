<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Provider;
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

    public function test_super_admin_can_create_provider_user(): void
    {
        $admin = $this->makeAdmin('super_admin');

        $response = $this->actingAs($admin, 'web')->post(route('admin.users.store'), [
            'user_type' => 'provider',
            'name' => 'New Owner',
            'phone' => '0599999911',
            'business_name' => 'New Diving Co',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['phone' => '0599999911', 'user_type' => 'provider']);
        $this->assertDatabaseHas('providers', ['business_name' => 'New Diving Co']);
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

    public function test_admin_can_suspend_a_user_and_tokens_are_revoked(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('test', ['customer'])->plainTextToken;

        $this->actingAs($admin, 'web')->put(route('admin.users.update', $customer), [
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'status' => 'suspended']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->makeAdmin('super_admin');

        $response = $this->actingAs($admin, 'web')->delete(route('admin.users.destroy', $admin));

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_admin_can_delete_another_user_soft_delete(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($admin, 'web')->delete(route('admin.users.destroy', $customer));

        $response->assertRedirect(route('admin.users.index'));
        $this->assertSoftDeleted('users', ['id' => $customer->id]);
    }

    public function test_content_admin_cannot_delete_users(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($admin, 'web')->delete(route('admin.users.destroy', $customer));

        $response->assertStatus(403);
    }
}
