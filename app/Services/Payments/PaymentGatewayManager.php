<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayInterface;
use Illuminate\Contracts\Foundation\Application;

/**
 * Which gateway a payment goes through. The default gateway (PAYMENT_GATEWAY) is for card, apple_pay and mada; Tabby and Tamara are payment methods with a gateway each, chosen
 * per payment (`payments.methods`). A gateway that is not enabled or not fully configured is not a half-working one: it is refused (UnavailablePaymentGateway), and says why in the
 * log, so no customer is sent to a checkout that cannot finish.
 */
class PaymentGatewayManager
{
    /** Gateways with a real checkout (the customer pays elsewhere). */
    public const ONLINE = ['tabby', 'tamara'];

    public function __construct(private readonly Application $app) {}

    /** @return list<string> */
    public function names(): array
    {
        return array_merge(['mock'], self::ONLINE);
    }

    public function enabled(string $name): bool
    {
        return in_array($name, self::ONLINE, true) && (bool) config("payments.gateways.{$name}.enabled");
    }

    /** What is missing for an online gateway to work: the names of the settings. Empty: it is ready. @return list<string> */
    public function missingSettings(string $name): array
    {
        $required = [
            'tabby' => ['public_key' => 'TABBY_PUBLIC_KEY', 'secret_key' => 'TABBY_SECRET_KEY', 'merchant_code' => 'TABBY_MERCHANT_CODE', 'webhook_secret' => 'TABBY_WEBHOOK_SECRET'],
            'tamara' => ['api_token' => 'TAMARA_API_TOKEN', 'notification_token' => 'TAMARA_NOTIFICATION_TOKEN'],
        ][$name] ?? [];

        return array_values(array_filter($required, fn ($env, $key) => blank(config("payments.gateways.{$name}.{$key}")), ARRAY_FILTER_USE_BOTH));
    }

    /** The gateway a payment by this method goes through. */
    public function gatewayNameForMethod(?string $method): string
    {
        $default = (string) config('payments.default');
        $own = $method !== null ? config("payments.methods.{$method}") : null;

        if ($own === null) {
            return $default;
        }

        // Where a gateway is not enabled and nothing is real (the default is the mock, outside production), the method works as it always has, through the mock. Anywhere else it
        // is the gateway's own, and an unready one refuses.
        if (! $this->enabled($own) && $default === 'mock' && ! $this->app->isProduction()) {
            return $default;
        }

        return $own;
    }

    /** Does a webhook for this gateway belong here? The default gateway, and an enabled online one. */
    public function acceptsWebhooksFor(string $name): bool
    {
        return $name === config('payments.default') || $this->enabled($name);
    }

    public function resolve(string $name): PaymentGatewayInterface
    {
        return match ($name) {
            // The mock reports every payment as successful without charging anyone, so it must never run where real customers book.
            'mock' => $this->app->isProduction()
                ? new UnavailablePaymentGateway('PAYMENT_GATEWAY=mock is refused in production: the mock gateway charges nobody. Use a real gateway (see config/payments.php).')
                : new MockPaymentGateway(),
            'tabby', 'tamara' => $this->online($name),
            // An unknown value used to fall back to the mock. It refuses instead, and lazily, so a typo cannot take unrelated pages down.
            default => new UnavailablePaymentGateway("Unknown payment gateway [{$name}]. Supported: mock, tabby, tamara."),
        };
    }

    private function online(string $name): PaymentGatewayInterface
    {
        if (! $this->enabled($name)) {
            return new UnavailablePaymentGateway(strtoupper($name).' is not enabled: set '.strtoupper($name).'_ENABLED=true and its credentials (see config/payments.php).');
        }

        if ($missing = $this->missingSettings($name)) {
            return new UnavailablePaymentGateway(strtoupper($name).' is enabled but is missing: '.implode(', ', $missing).'.');
        }

        $config = (array) config("payments.gateways.{$name}");

        return $name === 'tabby' ? new TabbyPaymentGateway($config) : new TamaraPaymentGateway($config);
    }
}
