<?php

namespace App\Services\Sms\Contracts;

interface SmsGatewayInterface
{
    /**
     * Send an SMS message to the given phone number.
     *
     * @return bool True if the gateway accepted the message for delivery.
     */
    public function send(string $phone, string $message): bool;
}
