<?php

namespace App\Services\Sms;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Log;

/**
 * Used in local/testing environments so automated tests and local dev
 * don't send real SMS messages or hit the real Taqnyat API. Selected via
 * SMS_DRIVER=log in .env / phpunit.xml.
 */
class LogSmsGateway implements SmsGatewayInterface
{
    public function send(string $phone, string $message): bool
    {
        Log::channel('stack')->info('LogSmsGateway (no real SMS sent)', [
            'phone' => $phone,
            'message' => $message,
        ]);

        return true;
    }
}
