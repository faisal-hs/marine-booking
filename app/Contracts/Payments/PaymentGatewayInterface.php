<?php

namespace App\Contracts\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Gateway-agnostic contract. A real driver (Stripe, Paymob, etc.) would
 * implement this exactly the same way MockPaymentGateway does — nothing
 * in PaymentService or the Controllers is gateway-specific; swapping the
 * bound implementation in PaymentServiceProvider is the only change
 * needed to switch providers.
 */
interface PaymentGatewayInterface
{
    /**
     * Initiates a charge. Supports BOTH confirmation styles: a real
     * gateway might return an immediate synchronous result (success/fail
     * known right away) OR a 'redirect_url' for the customer to complete
     * payment elsewhere, with the actual result arriving later via
     * handleWebhook(). Callers must handle both — this method's return
     * never promises the payment is already settled.
     *
     * @return array{provider_reference: string, status: string, redirect_url: ?string, raw: array}
     */
    public function charge(Payment $payment, array $params = []): array;

    /**
     * Parses and verifies an incoming webhook request into a normalized
     * result. Signature verification is entirely the driver's own
     * responsibility — this contract deliberately doesn't standardize
     * HOW verification happens (every gateway does it differently),
     * only WHAT comes out the other side.
     *
     * @return array{provider_reference: string, status: string, raw: array}
     */
    public function handleWebhook(Request $request): array;

    /**
     * @return array{status: string, refunded_amount: float, raw: array}
     */
    public function refund(Payment $payment, ?float $amount = null): array;
}
