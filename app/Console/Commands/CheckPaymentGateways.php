<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Is each online gateway ready? Without options it reads the settings: what is enabled, what is missing, where the gateway will call us. With --live it also makes ONE harmless call
 * to each enabled gateway with the credentials (a read, nothing is created or charged), so a wrong key shows now and not on a customer's first payment. Exits 1 when an enabled gateway
 * is not ready.
 */
class CheckPaymentGateways extends Command
{
    protected $signature = 'payments:check {--live : Also call each enabled gateway with its credentials (a read only)}';

    protected $description = 'Checks the Tabby and Tamara settings, and with --live their credentials.';

    public function handle(PaymentGatewayManager $gateways): int
    {
        $failed = false;
        $this->line('Default gateway (card, apple_pay, mada): '.config('payments.default').(app()->isProduction() && config('payments.default') === 'mock' ? '  <-- refused in production' : ''));

        foreach (PaymentGatewayManager::ONLINE as $name) {
            $this->newLine();
            $config = (array) config("payments.gateways.{$name}");
            $enabled = $gateways->enabled($name);
            $this->line(strtoupper($name).': '.($enabled ? 'enabled' : 'not enabled').'  ('.($config['environment'] ?? '?').')');

            if (! $enabled) {
                continue;
            }

            if ($missing = $gateways->missingSettings($name)) {
                $this->error('  missing: '.implode(', ', $missing));
                $failed = true;

                continue;
            }

            $this->line('  webhook address: '.route('webhooks.payments', ['gateway' => $name]).($name === 'tamara' ? '  (sent with each checkout)' : '  (register it: php artisan payments:tabby:register-webhook)'));

            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $this->warn('  APP_URL is not https: the gateway cannot call a plain-http or local address.');
            }

            if ($this->option('live')) {
                $failed = ! $this->ping($name, $config) || $failed;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** One read with the credentials. 2xx: they work. 401 or 403: they do not. */
    private function ping(string $name, array $config): bool
    {
        $request = Http::acceptJson()->timeout((int) ($config['timeout'] ?? 15));
        $response = $name === 'tabby'
            ? $request->withToken((string) $config['secret_key'])->get(rtrim((string) $config['base_url'], '/').'/api/v2/payments', ['limit' => 1])
            : $request->withToken((string) $config['api_token'])->get(rtrim((string) ($config['base_url'] ?: (($config['environment'] ?? 'sandbox') === 'production' ? 'https://api.tamara.co' : 'https://api-sandbox.tamara.co')), '/').'/checkout/payment-types', ['country' => $config['country_code'] ?? 'SA', 'currency' => 'SAR']);

        if ($response->successful()) {
            $this->info("  live check: the credentials work (HTTP {$response->status()})");

            return true;
        }

        $this->error("  live check: HTTP {$response->status()}".(in_array($response->status(), [401, 403], true) ? ' — the credentials were refused' : ''));

        return false;
    }
}
