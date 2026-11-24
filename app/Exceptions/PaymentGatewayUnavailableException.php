<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Thrown when something tries to move money through a payment gateway that
 * has been deliberately refused (see UnavailablePaymentGateway). Being an
 * HTTP exception, API clients get the project's standard JSON error envelope
 * with a 503 instead of an unexplained 500.
 *
 * The message is generic on purpose: it reaches customers. The real reason
 * (for example "the mock gateway is refused in production") is logged at
 * critical level by UnavailablePaymentGateway for whoever runs the server.
 *
 * Throwing this means NO charge, refund or webhook was processed and no
 * gateway was contacted, so callers may safely undo any bookkeeping they did
 * beforehand (PaymentService::initiate removes its pending payment row).
 */
class PaymentGatewayUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct(?\Throwable $previous = null, private readonly ?string $reason = null)
    {
        parent::__construct(null, 'Online payments are currently unavailable.', $previous);
    }

    /** Why (a missing setting, a gateway that could not be reached): for the log, and for the answer when the app is in debug mode. Never shown to a customer in production. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
