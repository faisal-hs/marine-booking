<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_failed_admin_logins_are_rate_limited(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'ratelimit-admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post(route('admin.login.submit'), [
                'email' => 'ratelimit-admin@example.com',
                'password' => 'wrong-password',
            ]);

            $response->assertSessionHasErrors('email');
        }

        $sixthAttempt = $this->post(route('admin.login.submit'), [
            'email' => 'ratelimit-admin@example.com',
            'password' => 'wrong-password',
        ]);

        $sixthAttempt->assertStatus(429);
    }

    public function test_rate_limit_is_scoped_per_email_not_global(): void
    {
        $userA = User::factory()->admin()->create(['email' => 'admin-a@example.com', 'password' => bcrypt('x')]);
        Admin::create(['user_id' => $userA->id, 'job_title' => 'A']);

        $userB = User::factory()->admin()->create(['email' => 'admin-b@example.com', 'password' => bcrypt('correct')]);
        Admin::create(['user_id' => $userB->id, 'job_title' => 'B']);

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('admin.login.submit'), [
                'email' => 'admin-a@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // Admin B, from the same test-client IP, must still be able to log
        // in — a global (IP-only) limiter would incorrectly block this too.
        $response = $this->post(route('admin.login.submit'), [
            'email' => 'admin-b@example.com',
            'password' => 'correct',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
    }
}
