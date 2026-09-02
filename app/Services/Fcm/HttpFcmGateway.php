<?php

namespace App\Services\Fcm;

use App\Contracts\Fcm\FcmGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses Firebase Cloud Messaging's HTTP v1 API request shape
 * (POST https://fcm.googleapis.com/v1/projects/{project_id}/messages:send).
 *
 * KNOWN LIMITATION, flagged explicitly rather than hidden: the v1 API
 * requires a short-lived OAuth2 access token obtained via a Google
 * service-account JSON key (JWT-signed token exchange). This class
 * accepts an already-valid access token from config
 * (services.fcm.access_token) rather than performing that OAuth2
 * exchange itself — implementing full service-account JWT signing
 * from scratch was out of scope for this first pass. A real deployment
 * needs EITHER a scheduled job that refreshes this config value
 * periodically, OR (preferably) a proper Firebase SDK/package
 * (e.g. kreait/laravel-firebase, not installed — Packagist isn't
 * reachable from this build environment) that handles token exchange
 * internally. Until one of those exists, this gateway will start
 * failing once the configured access token expires.
 */
class HttpFcmGateway implements FcmGatewayInterface
{
    public function __construct(protected array $config) {}

    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        try {
            $response = Http::withToken($this->config['access_token'])
                ->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$this->config['project_id']}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => array_map('strval', $data),
                    ],
                ]);

            if (! $response->successful()) {
                Log::channel('stack')->warning('HttpFcmGateway: send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel('stack')->error('HttpFcmGateway: exception during send', ['message' => $e->getMessage()]);

            return false;
        }
    }
}
