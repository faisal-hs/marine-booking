<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Tells Tabby where to send webhooks, and which secret header to repeat with each one (TABBY_WEBHOOK_HEADER: TABBY_WEBHOOK_SECRET), which is how PaymentWebhookController knows a
 * webhook is Tabby's. Run it once for the sandbox keys (with --test) and once for the live keys.
 */
class RegisterTabbyWebhook extends Command
{
    protected $signature = 'payments:tabby:register-webhook {--test : Register it for test payments (default: by TABBY_ENV)}';

    protected $description = 'Registers the webhook address and secret header with Tabby.';

    public function handle(): int
    {
        $config = (array) config('payments.gateways.tabby');

        foreach (['secret_key' => 'TABBY_SECRET_KEY', 'merchant_code' => 'TABBY_MERCHANT_CODE', 'webhook_secret' => 'TABBY_WEBHOOK_SECRET'] as $key => $env) {
            if (blank($config[$key] ?? null)) {
                $this->error("{$env} is not set.");

                return self::FAILURE;
            }
        }

        $url = route('webhooks.payments', ['gateway' => 'tabby']);
        $test = $this->option('test') || ($config['environment'] ?? 'sandbox') !== 'production';
        $response = Http::withToken((string) $config['secret_key'])->withHeaders(['X-Merchant-Code' => (string) $config['merchant_code']])->acceptJson()->asJson()
            ->timeout((int) ($config['timeout'] ?? 15))
            ->post(rtrim((string) $config['base_url'], '/').'/api/v1/webhooks', ['url' => $url, 'is_test' => $test, 'header' => ['title' => $config['webhook_header'], 'value' => $config['webhook_secret']]]);

        if (! $response->successful()) {
            $this->error("Tabby refused it: HTTP {$response->status()} ".mb_substr((string) $response->body(), 0, 300));

            return self::FAILURE;
        }

        $this->info('Registered '.$url.($test ? ' (for test payments)' : ' (for live payments)').'.');

        return self::SUCCESS;
    }
}
