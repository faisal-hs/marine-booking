<?php

namespace App\Services\Payments;

use App\Contracts\Payments\AsyncPaymentGateway;
use App\Exceptions\InvalidWebhookException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Payment;
use App\Services\Payments\Concerns\TalksToGateways;
use App\Support\Money\GatewayAmount;
use Illuminate\Http\Request;

/**
 * Tabby (https://docs.tabby.ai), pay in instalments. The flow:
 *
 *   1. charge()         POST /api/v2/checkout creates a session; the customer is sent to its `web_url`. A session Tabby will not offer (`rejected`: the amount is too high or low, or
 *                       the customer is not eligible) is a failed payment, with Tabby's reason.
 *   2. the customer approves at Tabby; Tabby sends a webhook (a custom header carries OUR secret) and sends the customer back to our return URL.
 *   3. verify()         GET /api/v2/payments/{id}: AUTHORIZED means approved. This is what is believed, never the webhook body.
 *   4. capture()        POST /api/v2/payments/{id}/captures takes the money (status CLOSED). A payment cannot be refunded before.
 *   void()              POST /api/v2/payments/{id}/close cancels an authorised payment that will not be captured (the hold lapsed, the booking was cancelled).
 *   refund()            POST /api/v2/payments/{id}/refunds, partial or full, of a captured payment.
 *
 * The session is created with the PUBLIC key; everything about an existing payment uses the SECRET key.
 */
class TabbyPaymentGateway implements AsyncPaymentGateway
{
    use TalksToGateways;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'tabby';
    }

    public function currencies(): array
    {
        return (array) ($this->config['currencies'] ?? []);
    }

    public function charge(Payment $payment, array $params = []): array
    {
        $payment->loadMissing('reservation.activity', 'reservation.customer.user');
        $buyer = $this->buyerOf($payment);
        $reservation = $payment->reservation;
        $amount = GatewayAmount::format($payment->amount, $payment->currency);
        $country = strtoupper((string) ($this->config['merchant_code'] ?? 'SA'));

        $response = $this->send('create session', 'POST', $this->url('/api/v2/checkout'), fn () => $this->client((string) ($this->config['public_key'] ?: $this->config['secret_key'])), [
            'payment' => [
                'amount' => $amount,
                'currency' => strtoupper($payment->currency),
                'description' => mb_substr('Booking '.$payment->reference_id, 0, 250),
                'buyer' => array_filter(['name' => $buyer['name'], 'phone' => $this->internationalPhone($buyer['phone'], $country), 'email' => $buyer['email']]),
                // One line for the booking, at what the customer pays (the price after any coupon, with any fee): so the lines add up to the amount, whatever the booking was made of.
                'order' => [
                    'reference_id' => $payment->reference_id,
                    'tax_amount' => GatewayAmount::format(0, $payment->currency),
                    'shipping_amount' => GatewayAmount::format(0, $payment->currency),
                    'discount_amount' => GatewayAmount::format(0, $payment->currency),
                    'items' => [[
                        'title' => mb_substr((string) ($reservation->activity?->title ?: 'Activity booking'), 0, 120),
                        'quantity' => 1,
                        'unit_price' => $amount,
                        'discount_amount' => GatewayAmount::format(0, $payment->currency),
                        'reference_id' => (string) $reservation->uuid,
                        'category' => 'Activities',
                    ]],
                ],
                'meta' => ['order_id' => $payment->reference_id, 'booking' => (string) $reservation->uuid],
            ],
            'lang' => app()->getLocale() === 'ar' ? 'ar' : 'en',
            'merchant_code' => $this->config['merchant_code'],
            'merchant_urls' => ['success' => $this->returnUrl($payment, 'success'), 'cancel' => $this->returnUrl($payment, 'cancel'), 'failure' => $this->returnUrl($payment, 'failure')],
        ], PaymentGatewayUnavailableException::class);

        $json = $response->json() ?? [];
        $paymentId = (string) data_get($json, 'payment.id', '');
        $webUrl = (string) data_get($json, 'configuration.available_products.installments.0.web_url', '');

        // Tabby answers 200 with `status: rejected` when it will not offer instalments for this customer or amount: a failed payment, with the reason.
        if (! $response->successful() || $paymentId === '' || data_get($json, 'status') !== 'created' || $webUrl === '') {
            $reason = $response->successful()
                ? $this->rejection((string) data_get($json, 'configuration.products.installments.rejection_reason', ''))
                : $this->reasonFrom($response);
            $this->log('warning', 'session refused', ['payment' => $payment->reference_id, 'http' => $response->status(), 'status' => data_get($json, 'status'), 'reason' => $reason]);

            return ['provider_reference' => $payment->reference_id, 'status' => 'failed', 'redirect_url' => null, 'raw' => ['gateway' => 'tabby', 'session' => data_get($json, 'id'), 'reason' => $reason, 'code' => 'declined']];
        }

        return [
            'provider_reference' => $paymentId,
            'status' => 'pending',
            'redirect_url' => $webUrl,
            'raw' => ['gateway' => 'tabby', 'session' => data_get($json, 'id'), 'payment' => $paymentId, 'expires_at' => data_get($json, 'payment.expires_at'), 'checkout_url' => $webUrl],
        ];
    }

    public function handleWebhook(Request $request): array
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $given = (string) $request->header((string) $this->config['webhook_header'], '');

        // Tabby signs nothing: it repeats a header WE chose when the webhook was registered. So the secret is compared in constant time, and no secret set means nothing is accepted.
        if ($secret === '' || $given === '' || ! hash_equals($secret, $given)) {
            $this->log('warning', 'webhook refused: the secret header is missing or wrong');

            throw new InvalidWebhookException('tabby', 'the secret header is missing or wrong');
        }

        $id = (string) $request->input('id', '');
        $status = strtoupper((string) $request->input('status', ''));

        if ($id === '') {
            throw new InvalidWebhookException('tabby', 'it names no payment');
        }

        return ['provider_reference' => $id, 'status' => 'pending', 'event_key' => "{$id}:{$status}", 'type' => $status, 'raw' => ['id' => $id, 'status' => $status, 'reference' => $request->input('order.reference_id')]];
    }

    public function verify(Payment $payment): array
    {
        $json = $this->payment($payment);
        $gatewayStatus = strtoupper((string) ($json['status'] ?? ''));
        $captured = collect($json['captures'] ?? [])->sum(fn ($c) => (float) ($c['amount'] ?? 0));

        $status = match ($gatewayStatus) {
            'AUTHORIZED' => 'authorized',
            'CLOSED' => $captured > 0 ? 'captured' : 'cancelled',
            'REJECTED' => 'declined',
            'EXPIRED' => 'expired',
            default => 'pending',   // CREATED
        };

        return [
            'status' => $status,
            'amount' => isset($json['amount']) ? (float) $json['amount'] : null,
            'currency' => isset($json['currency']) ? strtoupper((string) $json['currency']) : null,
            'reference' => data_get($json, 'order.reference_id'),
            'gateway_status' => $gatewayStatus,
            'raw' => ['gateway' => 'tabby', 'payment' => $payment->payment_id, 'status' => $gatewayStatus, 'captured' => $captured],
        ];
    }

    public function capture(Payment $payment): array
    {
        $response = $this->send('capture', 'POST', $this->url("/api/v2/payments/{$payment->payment_id}/captures"), fn () => $this->client((string) $this->config['secret_key']), [
            'amount' => GatewayAmount::format($payment->amount, $payment->currency),
            'reference_id' => $payment->reference_id,
        ]);

        if ($response->successful()) {
            return ['captured' => true, 'raw' => ['gateway' => 'tabby', 'capture' => 'done']];
        }

        // A capture that was refused may be one that already happened (a retry after a timeout): the gateway is asked, and what it says is the answer.
        $this->log('warning', 'capture refused', ['payment' => $payment->reference_id, 'http' => $response->status(), 'reason' => $this->reasonFrom($response)]);

        return ['captured' => $this->verify($payment)['status'] === 'captured', 'raw' => ['gateway' => 'tabby', 'capture' => 'refused', 'http' => $response->status()]];
    }

    public function void(Payment $payment): void
    {
        $response = $this->send('close', 'POST', $this->url("/api/v2/payments/{$payment->payment_id}/close"), fn () => $this->client((string) $this->config['secret_key']));

        // A payment that cannot be closed (never approved: it expires by itself; already closed) is not a problem. Only an unreachable gateway is, and that was thrown above.
        if (! $response->successful()) {
            $this->log('info', 'close refused (nothing to close)', ['payment' => $payment->reference_id, 'http' => $response->status()]);
        }
    }

    public function refund(Payment $payment, ?float $amount = null): array
    {
        $remaining = round((float) $payment->amount - (float) $payment->refunded_amount, 2);
        $amount = $amount === null ? $remaining : min($amount, $remaining);

        $response = $this->send('refund', 'POST', $this->url("/api/v2/payments/{$payment->payment_id}/refunds"), fn () => $this->client((string) $this->config['secret_key']), [
            'amount' => GatewayAmount::format($amount, $payment->currency),
            'reason' => 'Booking cancelled',
            // The same cumulative refund is the same reference: sending it twice cannot refund twice.
            'reference_id' => $payment->reference_id.'-R'.(int) round(((float) $payment->refunded_amount + $amount) * 100),
        ]);

        if (! $response->successful()) {
            $this->log('error', 'refund refused', ['payment' => $payment->reference_id, 'http' => $response->status(), 'reason' => $this->reasonFrom($response)]);

            throw new PaymentGatewayException("tabby refused the refund: {$this->reasonFrom($response)}");
        }

        return ['status' => 'refunded', 'refunded_amount' => round($amount, 2), 'raw' => ['gateway' => 'tabby', 'refund' => 'done', 'amount' => $amount]];
    }

    /** @return array<string, mixed> */
    private function payment(Payment $payment): array
    {
        $response = $this->send('retrieve payment', 'GET', $this->url("/api/v2/payments/{$payment->payment_id}"), fn () => $this->client((string) $this->config['secret_key']));

        if (! $response->successful()) {
            $this->log('error', 'payment could not be read', ['payment' => $payment->reference_id, 'http' => $response->status()]);

            throw new PaymentGatewayException("tabby could not tell the state of payment {$payment->reference_id} (HTTP {$response->status()})");
        }

        return (array) $response->json();
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config['base_url'], '/').$path;
    }

    private function rejection(string $code): string
    {
        return match ($code) {
            'order_amount_too_high' => 'The amount is too high for Tabby.',
            'order_amount_too_low' => 'The amount is too low for Tabby.',
            'not_available' => 'Tabby is not available for this customer.',
            default => 'Tabby did not accept this payment.',
        };
    }
}
