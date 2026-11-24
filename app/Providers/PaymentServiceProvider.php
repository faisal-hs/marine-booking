<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);

        // The DEFAULT gateway (PAYMENT_GATEWAY): the one for every payment method that has no gateway of its own. Which gateways exist, and which are refused (the mock in
        // production, one that is not enabled, a value nobody knows), is decided in PaymentGatewayManager::resolve(). Tabby and Tamara are chosen per payment, by the method.
        $this->app->bind(PaymentGatewayInterface::class, fn ($app) => $app->make(PaymentGatewayManager::class)->resolve((string) config('payments.default')));
    }
}
