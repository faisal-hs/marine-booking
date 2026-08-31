<?php

namespace App\Services\Sms;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOTE: The exact endpoint path and payload shape below follow a standard
 * Bearer-token REST SMS gateway pattern. Verify this against Taqnyat's
 * current official API documentation / Postman collection before relying
 * on it in production — API contracts for third-party services can change,
 * and this was not verified against live, current Taqnyat docs.
 */
class TaqnyatSmsGateway implements SmsGatewayInterface
{
    public function __construct(protected array $config) {}

    public function send(string $phone, string $message): bool
    {
        try {
            $response = Http::withToken($this->config['api_key'])
                ->acceptJson()
                ->post(rtrim($this->config['base_url'], '/') . '/messages', [
                    'recipients' => [$phone],
                    'body' => $message,
                    'sender' => $this->config['sender'],
                ]);

            if (! $response->successful()) {
                Log::channel('stack')->warning('Taqnyat SMS send failed', [
                    'phone' => $phone,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::channel('stack')->error('Taqnyat SMS gateway exception', [
                'phone' => $phone,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
