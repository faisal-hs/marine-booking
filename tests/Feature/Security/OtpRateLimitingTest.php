<?php

namespace Tests\Feature\Security;

use App\Models\Otp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function otpRequestEndpoints(): array
    {
        return [
            'customer' => ['/api/v1/auth/customer/otp/request', ['name' => 'Throttle Test']],
            'provider' => ['/api/v1/auth/provider/otp/request', ['name' => 'Throttle Test', 'business_name' => 'Throttle Divers']],
            'any account' => ['/api/v1/auth/otp/request', []],
        ];
    }

    /**
     * Asking for a code sends a message to whatever phone number is given. Without a limit the endpoint can be used to
     * flood a stranger's phone, or to run up the SMS bill.
     *
     * @dataProvider otpRequestEndpoints
     */
    #[DataProvider('otpRequestEndpoints')]
    public function test_asking_for_a_code_is_limited_per_phone(string $url, array $extra): void
    {
        $payload = ['phone' => '0511111150'] + $extra;

        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->postJson($url, $payload)->status(), 'Request '.($i + 1).' was limited too early');
        }

        $this->postJson($url, $payload)->assertStatus(429);

        // Another phone from the same place is not affected by that phone's limit.
        $this->assertNotSame(429, $this->postJson($url, ['phone' => '0511111151'] + $extra)->status());
    }

    public function test_asking_for_codes_is_limited_per_place_so_a_script_cannot_walk_through_many_numbers(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $phone = '05222'.str_pad((string) $i, 5, '0', STR_PAD_LEFT);
            $this->assertNotSame(429, $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => $phone, 'name' => 'Walker'])->status(), 'Number '.($i + 1).' was limited too early');
        }

        // Thirty different numbers from one address in a minute: the next one is refused, whatever the number.
        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0522299999', 'name' => 'Walker'])->assertStatus(429);
    }

    public function test_the_limit_on_asking_for_codes_lifts_after_a_minute(): void
    {
        $payload = ['phone' => '0511111160', 'name' => 'Patient'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/customer/otp/request', $payload);
        }
        $this->postJson('/api/v1/auth/customer/otp/request', $payload)->assertStatus(429);

        $this->travel(61)->seconds();

        $this->assertNotSame(429, $this->postJson('/api/v1/auth/customer/otp/request', $payload)->status());
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
