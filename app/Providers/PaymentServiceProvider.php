<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Services\Payments\MockPaymentGateway;
use App\Services\Payments\UnavailablePaymentGateway;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, function ($app) {
            $gateway = (string) config('payments.default');

            return match ($gateway) {
                // The mock reports every payment as successful without charging
                // anyone, so it must never run where real customers book.
                'mock' => $app->isProduction()
                    ? new UnavailablePaymentGateway('PAYMENT_GATEWAY=mock is refused in production: the mock gateway charges nobody. Implement a real gateway (see config/payments.php).')
                    : new MockPaymentGateway(),

                // An unknown value used to fall back to the mock. It now refuses
                // instead, and lazily, so a typo cannot take unrelated pages down.
                default => new UnavailablePaymentGateway("Unknown payment gateway [{$gateway}]. Supported: mock."),
            };
        });
    }
}
