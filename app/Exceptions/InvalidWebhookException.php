<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * A notification that did not prove it came from the gateway (a missing or wrong secret or signature, an expired token). It is a 401: the gateway is told it was refused, and nothing is
 * changed, looked up or logged beyond the fact that it happened.
 */
class InvalidWebhookException extends UnauthorizedHttpException
{
    public function __construct(string $gateway, string $reason)
    {
        parent::__construct('Webhook', "The {$gateway} notification could not be verified: {$reason}.");
    }
}
