<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWebAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get(route('admin.login'))->assertOk()->assertSee('Marine Booking Admin');
    }

    public function test_admin_can_login_and_reach_dashboard(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'webadmin@example.com',
            'password' => bcrypt('password123'),
        ]);
        Admin::create(['user_id' => $user->id, 'job_title' => 'Tester']);

        $response = $this->post(route('admin.login.submit'), [
            'email' => 'webadmin@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'webadmin2@example.com',
            'password' => bcrypt('password123'),
        ]);
        Admin::create(['user_id' => $user->id, 'job_title' => 'Tester']);

        $response = $this->post(route('admin.login.submit'), [
            'email' => 'webadmin2@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_non_admin_user_cannot_reach_dashboard_even_if_logged_in_directly(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'web');

        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest('web');
    }

    public function test_guest_is_redirected_from_dashboard(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_logout(): void
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Tester']);

        $this->actingAs($user, 'web');

        $response = $this->post(route('admin.logout'));

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest('web');
    }
}
