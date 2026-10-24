<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * Keeps .env.example honest. A configuration variable that is read by the
 * code but missing from the example is invisible to whoever sets up the
 * next environment, and a risky default can sit there unnoticed (for
 * example SMS_DRIVER, whose built-in default is the REAL SMS gateway).
 *
 * Adding an env() call to one of this project's own config files, or to
 * the project's blocks in config/services.php, fails this test until the
 * variable is documented in .env.example (a commented-out line counts).
 *
 * Deliberately NOT checked: the many optional knobs of stock Laravel
 * config (Redis, Papertrail, Postmark, ...). The framework's own example
 * file omits those too, and listing them would only bury the variables
 * that matter here.
 */
class EnvExampleCoverageTest extends TestCase
{
    /** Config files whose every env() key belongs to this project. */
    private const OWNED_CONFIG_FILES = ['branding', 'otp', 'payments', 'reservations'];

    /** config/services.php mixes stock keys with ours; these prefixes are ours. */
    private const OWNED_SERVICE_PREFIXES = ['SMS_', 'TAQNYAT_', 'FCM_'];

    /** Stock-framework variables we have chosen to document as well. */
    private const ALSO_DOCUMENTED = ['SANCTUM_TOKEN_PREFIX', 'SANCTUM_STATEFUL_DOMAINS'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return string[] every KEY used as env('KEY' ...) in a config file */
    private function envKeysIn(string $configFile): array
    {
        preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", file_get_contents($configFile), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return string[] every variable named in .env.example, active or commented out */
    private function documentedKeys(): array
    {
        preg_match_all('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/m', file_get_contents($this->root() . '/.env.example'), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return string[] */
    private function requiredKeys(): array
    {
        $required = [];

        foreach (self::OWNED_CONFIG_FILES as $name) {
            $required = array_merge($required, $this->envKeysIn("{$this->root()}/config/{$name}.php"));
        }

        foreach ($this->envKeysIn($this->root() . '/config/services.php') as $key) {
            foreach (self::OWNED_SERVICE_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $required[] = $key;
                }
            }
        }

        return array_values(array_unique(array_merge($required, self::ALSO_DOCUMENTED)));
    }

    public function test_the_scan_actually_finds_the_project_keys(): void
    {
        // Guards the guard: if the regex ever matched nothing, the next test would pass vacuously.
        $required = $this->requiredKeys();

        foreach (['OTP_LENGTH', 'PAYMENT_GATEWAY', 'RESERVATION_HOLD_MINUTES', 'SMS_DRIVER', 'FCM_DRIVER', 'TAQNYAT_API_KEY', 'BRANDING_FAVICON'] as $expected) {
            $this->assertContains($expected, $required, "Expected the scan to find {$expected}.");
        }
    }

    public function test_every_project_env_variable_is_documented_in_env_example(): void
    {
        $missing = array_values(array_diff($this->requiredKeys(), $this->documentedKeys()));

        $this->assertSame(
            [],
            $missing,
            'These variables are read by the code but missing from .env.example: ' . implode(', ', $missing) .
                '. Add each one (a commented-out line is fine for optional settings).'
        );
    }

    public function test_the_example_defaults_to_safe_local_drivers(): void
    {
        $values = Dotenv::parse(file_get_contents($this->root() . '/.env.example'));

        // A fresh clone must never reach a real SMS gateway, push provider or payment processor.
        $this->assertSame('log', $values['SMS_DRIVER'] ?? null, 'SMS_DRIVER must default to "log" locally.');
        $this->assertSame('log', $values['FCM_DRIVER'] ?? null, 'FCM_DRIVER must default to "log" locally.');
        $this->assertSame('mock', $values['PAYMENT_GATEWAY'] ?? null, 'PAYMENT_GATEWAY must default to "mock" locally.');

        // config/branding.php falls back to "Marine Booking"; the stock "Laravel" value would override it.
        $this->assertNotSame('Laravel', $values['APP_NAME'] ?? null, 'APP_NAME should be the project name, not "Laravel".');
    }
}
