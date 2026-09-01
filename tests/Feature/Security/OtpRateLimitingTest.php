<?php

namespace Tests\Feature\Security;

use App\Models\Otp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 26 hardening — proves OTP brute force is no longer possible.
 *
 * Covers audit CRITICAL-2: OTP verification previously had no attempt
 * limiting at all (a 4-digit code has only 10,000 possibilities, well
 * within reach of an unthrottled script inside the 5-minute expiry
 * window). These tests exercise the actual `throttle:otp-verify` /
 * `throttle:otp-resend` middleware now applied in routes/api_v1.php,
 * not just the underlying OtpService logic in isolation.
 */
class OtpRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_wrong_otp_attempts_are_rate_limited(): void
    {
        $phone = '0511111199';

        $this->postJson('/api/v1/auth/customer/otp/request', [
            'phone' => $phone,
            'name' => 'Rate Limit Test',
        ]);

        // The named limiter allows 5 attempts/minute (see
        // AppServiceProvider::configureRateLimiting). The first 5 wrong
        // attempts should reach the controller and fail validation (422);
        // the 6th must be rejected by the limiter itself (429) before the
        // controller/service ever runs.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/customer/otp/verify', [
                'phone' => $phone,
                'code' => '0000',
            ]);

            $response->assertStatus(422);
        }

        $sixthAttempt = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => $phone,
            'code' => '0000',
        ]);

        $sixthAttempt->assertStatus(429);
    }

    public function test_rate_limit_is_scoped_per_phone_not_global(): void
    {
        $phoneA = '0511111100';
        $phoneB = '0511111101';

        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => $phoneA, 'name' => 'A']);
        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => $phoneB, 'name' => 'B']);

        // Exhaust phone A's limit.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => $phoneA, 'code' => '0000']);
        }

        // Phone B, from the same test-client IP, must be unaffected — a
        // global (IP-only) limiter would incorrectly block this too.
        $response = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => $phoneB,
            'code' => '0000',
        ]);

        $response->assertStatus(422); // reaches the controller — not 429.
    }

    public function test_repeated_resend_requests_are_rate_limited(): void
    {
        $phone = '0522222200';

        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => $phone, 'name' => 'Resend Test']);

        // Limiter allows 3/minute. Whatever the business-logic cooldown
        // decides for each individual call, the 4th call must never even
        // reach the controller.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/customer/otp/resend', ['phone' => $phone]);
        }

        $fourth = $this->postJson('/api/v1/auth/customer/otp/resend', ['phone' => $phone]);

        $fourth->assertStatus(429);
    }

    public function test_used_otp_cannot_be_verified_twice(): void
    {
        $phone = '0533333300';

        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => $phone, 'name' => 'Reuse Test']);

        $first = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => $phone,
            'code' => '1234',
        ]);

        $first->assertOk();

        $second = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => $phone,
            'code' => '1234',
        ]);

        $second->assertStatus(422);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $phone = '0544444400';

        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => $phone, 'name' => 'Expiry Test']);

        Otp::where('phone', $phone)->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => $phone,
            'code' => '1234',
        ]);

        $response->assertStatus(422);
    }
}
