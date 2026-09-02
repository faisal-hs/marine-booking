<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Services\Payments\MockPaymentGateway;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, function () {
            return match (config('payments.default')) {
                'mock' => new MockPaymentGateway(),
                default => new MockPaymentGateway(),
            };
        });
    }
}
