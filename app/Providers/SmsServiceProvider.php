<?php

namespace App\Providers;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\TaqnyatSmsGateway;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGatewayInterface::class, function ($app) {
            $driver = config('services.sms.driver', 'taqnyat');

            // Every driver is listed explicitly. A catch-all arm used to send any
            // unrecognised value (a typo such as "lgo") to the REAL gateway.
            return match ($driver) {
                'log' => new LogSmsGateway(),
                'taqnyat' => new TaqnyatSmsGateway(config('services.taqnyat')),
                default => throw new InvalidArgumentException(
                    "Unknown SMS driver [{$driver}]. Set SMS_DRIVER to one of: log, taqnyat."
                ),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
