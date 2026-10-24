<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bound in place of a gateway that must not be used: the mock gateway in
 * production, or a PAYMENT_GATEWAY value nothing implements.
 *
 * It refuses when it is USED, not when it is created. Several controllers
 * (including the admin payment pages) have PaymentService, and therefore the
 * gateway, injected into their constructors; throwing at resolution time
 * would make merely opening the admin payments list fail in production.
 * Refusing at the moment money would move keeps everything else working.
 *
 * Every refusal is logged at critical level with the real reason, because the
 * exception the caller sees is deliberately generic.
 */
class UnavailablePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly string $reason) {}

    public function charge(Payment $payment, array $params = []): array
    {
        $this->refuse('charge');
    }

    public function handleWebhook(Request $request): array
    {
        $this->refuse('webhook');
    }

    public function refund(Payment $payment, ?float $amount = null): array
    {
        $this->refuse('refund');
    }

    private function refuse(string $operation): never
    {
        Log::critical("Payment {$operation} refused: {$this->reason}");

        throw new PaymentGatewayUnavailableException();
    }
}
