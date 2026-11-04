<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The fixed one-time code (OTP_DEFAULT_CODE) is controlled by an explicit switch,
 * OTP_DEFAULT_CODE_ENABLED, that works in ANY environment:
 *   true  = on everywhere (a staging server with no SMS provider yet),
 *   false = off everywhere (even locally), and
 *   unset = on only in local and testing, exactly as before.
 *
 * The random codes in these tests are 8 digits long, so they can never be mistaken for the
 * 4-digit default ("00001234" is not "1234"), and nothing here can flake on a lucky draw.
 */
class DefaultOtpCodeTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT = '1234';

    private function setUpOtp(mixed $switch, ?string $code, string $environment): void
    {
        config(['otp.default_code_enabled' => $switch, 'otp.default_code' => $code, 'otp.length' => 8]);
        $this->app->detectEnvironment(fn() => $environment);
    }

    /** @var list<array{0: string, 1: string}> [level, message] of everything logged since captureLogs() */
    private array $logged = [];

    /** Records what is logged, without replacing the logger (the SMS gateway writes to it too). */
    private function captureLogs(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = [$event->level, $event->message];
        });
    }

    private function loggedAt(string $level, string $containing): int
    {
        return count(array_filter($this->logged, fn(array $l) => $l[0] === $level && str_contains($l[1], $containing)));
    }

    private function issuedCode(): string
    {
        app(OtpService::class)->issue('0500000001', 'login');

        return Otp::where('phone', '0500000001')->latest('id')->value('code');
    }

    /** @return array<string, array{0: mixed, 1: string, 2: bool}> [switch, environment, fixed code used] */
    public static function combinations(): array
    {
        return [
            'unset, testing' => [null, 'testing', true],
            'unset, local' => [null, 'local', true],
            'unset, staging' => [null, 'staging', false],
            'unset, production' => [null, 'production', false],
            'empty, staging (an empty value is "unset")' => ['', 'staging', false],
            'empty, local (an empty value is "unset")' => ['', 'local', true],
            'on, staging' => [true, 'staging', true],
            'on, production' => [true, 'production', true],
            'on, local' => [true, 'local', true],
            'off, local' => [false, 'local', false],
            'off, testing' => [false, 'testing', false],
            'off, production' => [false, 'production', false],
            'the string "true", staging' => ['true', 'staging', true],
            'the string "false", local' => ['false', 'local', false],
            'the string "0", local' => ['0', 'local', false],
            'the string "1", staging' => ['1', 'staging', true],
        ];
    }

    #[DataProvider('combinations')]
    public function test_the_fixed_code_follows_the_switch(mixed $switch, string $environment, bool $fixed): void
    {
        $this->setUpOtp($switch, self::DEFAULT, $environment);

        $code = $this->issuedCode();

        $fixed ? $this->assertSame(self::DEFAULT, $code) : $this->assertNotSame(self::DEFAULT, $code);
        $this->assertSame($fixed ? 4 : 8, strlen($code));
    }

    public function test_switching_it_on_without_a_code_fails_closed_and_says_so(): void
    {
        $this->captureLogs();

        foreach ([null, ''] as $missing) {
            $this->setUpOtp(true, $missing, 'staging');

            $this->assertSame(8, strlen($this->issuedCode()), 'A switch that is on with no code behind it must fall back to random codes.');
        }

        $this->assertSame(2, $this->loggedAt('error', 'OTP_DEFAULT_CODE is empty'));
    }

    public function test_a_fixed_code_in_production_is_logged_as_a_warning_every_time(): void
    {
        $this->captureLogs();
        $this->setUpOtp(true, self::DEFAULT, 'production');

        $this->issuedCode();
        $this->issuedCode();

        $this->assertSame(2, $this->loggedAt('warning', 'PRODUCTION'));
    }

    public function test_no_warning_is_logged_outside_production_or_when_it_is_off(): void
    {
        $this->captureLogs();

        $this->setUpOtp(true, self::DEFAULT, 'staging');
        $this->issuedCode();
        $this->setUpOtp(false, self::DEFAULT, 'production');
        $this->issuedCode();

        $this->assertSame(0, $this->loggedAt('warning', 'FIXED'), 'Nothing to warn about: staging is not production, and off is off.');
    }

    public function test_it_applies_to_customers_and_providers_alike_even_in_production(): void
    {
        $this->setUpOtp(true, self::DEFAULT, 'production');

        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0511000001', 'name' => 'Customer One'])->assertSuccessful();
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '0511000001', 'code' => self::DEFAULT])->assertSuccessful()->assertJsonPath('data.user.user_type', 'customer');

        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => '0511000002', 'owner_name' => 'Owner Two', 'business_name' => 'Two Divers'])->assertSuccessful();
        $this->postJson('/api/v1/auth/provider/otp/verify', ['phone' => '0511000002', 'code' => self::DEFAULT])->assertSuccessful()->assertJsonPath('data.user.user_type', 'provider');
    }

    public function test_with_the_switch_off_the_fixed_code_is_refused_for_customers_and_providers(): void
    {
        $this->setUpOtp(false, self::DEFAULT, 'production');

        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0511000003', 'name' => 'Customer Three'])->assertSuccessful();
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '0511000003', 'code' => self::DEFAULT])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => '0511000004', 'owner_name' => 'Owner Four', 'business_name' => 'Four Divers'])->assertSuccessful();
        $this->postJson('/api/v1/auth/provider/otp/verify', ['phone' => '0511000004', 'code' => self::DEFAULT])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_real_code_still_works_when_the_fixed_code_is_off(): void
    {
        $this->setUpOtp(false, self::DEFAULT, 'staging');
        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0511000005', 'name' => 'Customer Five'])->assertSuccessful();

        $real = Otp::where('phone', '0511000005')->latest('id')->value('code');

        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '0511000005', 'code' => $real])->assertSuccessful();
    }
}
