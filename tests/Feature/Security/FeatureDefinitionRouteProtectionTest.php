<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 26 hardening — regression coverage for audit CRITICAL-1.
 *
 * The feature-definitions admin routes were previously registered outside
 * the ['auth:web', 'admin.access'] group in routes/web.php, relying only
 * on the controller's own authorize() calls (and, incidentally, on
 * Laravel's default guest-denial for non-nullable policy parameters) for
 * protection. This proves the route itself — not just the controller — is
 * now guarded for every failure mode: guest, wrong role, inactive account,
 * and insufficient permission.
 */
class FeatureDefinitionRouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(string $role, string $status = 'active'): User
    {
        $user = User::factory()->admin()->create(['status' => $status]);
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('admin.feature-definitions.index'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_non_admin_web_user_is_denied(): void
    {
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer, 'web')->get(route('admin.feature-definitions.index'));

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest('web'); // admin.access logs the session out
    }

    public function test_inactive_admin_is_denied(): void
    {
        $admin = $this->makeAdmin('content_admin', status: 'inactive');

        $response = $this->actingAs($admin, 'web')->get(route('admin.feature-definitions.index'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_without_permission_is_denied(): void
    {
        $admin = $this->makeAdmin('support_admin'); // has no feature_definitions.* permissions

        $response = $this->actingAs($admin, 'web')->get(route('admin.feature-definitions.index'));

        $response->assertStatus(403);
    }

    public function test_authorized_admin_reaches_the_controller(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.feature-definitions.index'));

        $response->assertOk();
    }
}
