<?php

namespace App\Providers;

use App\Contracts\Fcm\FcmGatewayInterface;
use App\Services\Fcm\HttpFcmGateway;
use App\Services\Fcm\LogFcmGateway;
use Illuminate\Support\ServiceProvider;

class FcmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FcmGatewayInterface::class, function ($app) {
            $driver = config('services.fcm.driver', 'log');

            return match ($driver) {
                'log' => new LogFcmGateway(),
                default => new HttpFcmGateway(config('services.fcm')),
            };
        });
    }
}
