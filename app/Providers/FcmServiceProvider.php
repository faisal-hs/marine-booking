<?php

namespace App\Providers;

use App\Contracts\Fcm\FcmGatewayInterface;
use App\Services\Fcm\HttpFcmGateway;
use App\Services\Fcm\LogFcmGateway;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class FcmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FcmGatewayInterface::class, function ($app) {
            $driver = config('services.fcm.driver', 'log');

            // Every driver is listed explicitly. A catch-all arm used to send any
            // unrecognised value (a typo such as "lgo") to the REAL FCM service.
            return match ($driver) {
                'log' => new LogFcmGateway(),
                'http' => new HttpFcmGateway(config('services.fcm')),
                default => throw new InvalidArgumentException(
                    "Unknown FCM driver [{$driver}]. Set FCM_DRIVER to one of: log, http."
                ),
            };
        });
    }
}
