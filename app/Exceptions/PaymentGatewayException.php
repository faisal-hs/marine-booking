<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A payment gateway could not be reached or refused a call about a payment that already exists (verifying it, capturing it, refunding it). Nothing was settled: a webhook that meets
 * this is answered with an error so the gateway sends it again, and `payments:reconcile` tries again by itself. The message is for the logs; the customer is only told the payment is
 * still being confirmed.
 */
class PaymentGatewayException extends HttpException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(502, $message, $previous);
    }
}
