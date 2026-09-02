<?php

namespace App\Services\Fcm;

use App\Contracts\Fcm\FcmGatewayInterface;
use Illuminate\Support\Facades\Log;

/**
 * Used in local/testing environments so automated tests and local dev
 * don't send real push notifications or require real Firebase
 * credentials. Selected via FCM_DRIVER=log in .env / phpunit.xml —
 * mirrors LogSmsGateway's exact role for the SMS gateway.
 */
class LogFcmGateway implements FcmGatewayInterface
{
    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        Log::channel('stack')->info('LogFcmGateway (no real push sent)', [
            'token' => $token,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        return true;
    }
}
