<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Simulates both confirmation styles a real gateway might use:
 *   - 'sync_success' / 'sync_failure': result known immediately from charge()
 *   - 'async': charge() returns 'pending' + a redirect_url, and the real
 *     result only arrives later via handleWebhook() — exactly like a
 *     real hosted-checkout gateway.
 * Controlled via $params['simulate'] so tests can deterministically
 * exercise every path without needing real network calls.
 */
class MockPaymentGateway implements PaymentGatewayInterface
{
    public function charge(Payment $payment, array $params = []): array
    {
        $simulate = $params['simulate'] ?? 'sync_success';
        $reference = 'mock_' . Str::uuid();

        return match ($simulate) {
            'sync_success' => [
                'provider_reference' => $reference,
                'status' => 'succeeded',
                'redirect_url' => null,
                'raw' => ['simulate' => $simulate, 'amount' => (float) $payment->amount],
            ],
            'sync_failure' => [
                'provider_reference' => $reference,
                'status' => 'failed',
                'redirect_url' => null,
                'raw' => ['simulate' => $simulate, 'reason' => 'Simulated decline'],
            ],
            'async' => [
                'provider_reference' => $reference,
                'status' => 'pending',
                'redirect_url' => "https://mock-gateway.test/checkout/{$reference}",
                'raw' => ['simulate' => $simulate],
            ],
            default => throw ValidationException::withMessages([
                'simulate' => ["Unknown mock simulation mode '{$simulate}'."],
            ]),
        };
    }

    /**
     * Verifies a simple HMAC signature against a shared secret — stands
     * in for whatever signature scheme a real gateway would use (Stripe's
     * Stripe-Signature header, etc.). A real driver's verification logic
     * lives entirely inside ITS OWN class, never in PaymentService or the
     * Controller — this is the whole point of the interface boundary.
     */
    public function handleWebhook(Request $request): array
    {
        $payload = $request->all();
        $signature = $request->header('X-Mock-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), (string) config('payments.mock.webhook_secret'));

        if (! $signature || ! hash_equals($expected, $signature)) {
            throw ValidationException::withMessages([
                'signature' => ['Invalid webhook signature.'],
            ]);
        }

        return [
            'provider_reference' => $payload['provider_reference'] ?? '',
            'status' => $payload['status'] ?? 'failed',
            'raw' => $payload,
        ];
    }

    public function refund(Payment $payment, ?float $amount = null): array
    {
        $refundAmount = $amount ?? (float) $payment->amount;

        if ($refundAmount > (float) $payment->amount - (float) $payment->refunded_amount) {
            throw ValidationException::withMessages([
                'amount' => ['Refund amount exceeds the remaining refundable balance.'],
            ]);
        }

        return [
            'status' => 'succeeded',
            'refunded_amount' => $refundAmount,
            'raw' => ['simulated_refund' => true],
        ];
    }
}
