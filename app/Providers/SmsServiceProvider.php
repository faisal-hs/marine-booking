<?php

namespace App\Providers;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\TaqnyatSmsGateway;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGatewayInterface::class, function ($app) {
            $driver = config('services.sms.driver', 'taqnyat');

            return match ($driver) {
                'log' => new LogSmsGateway(),
                default => new TaqnyatSmsGateway(config('services.taqnyat')),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
