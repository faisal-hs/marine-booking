<?php

namespace App\Contracts\Fcm;

interface FcmGatewayInterface
{
    /**
     * Sends a push notification to a single device token.
     *
     * @return bool True if the gateway accepted the message for delivery.
     */
    public function send(string $token, string $title, string $body, array $data = []): bool;
}
