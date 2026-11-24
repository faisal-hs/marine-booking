<?php

namespace App\Services\Payments;

use App\Contracts\Payments\AsyncPaymentGateway;
use App\Exceptions\InvalidWebhookException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Payment;
use App\Services\Payments\Concerns\TalksToGateways;
use App\Support\Jwt;
use App\Support\Money\GatewayAmount;
use Illuminate\Http\Request;

/**
 * Tamara (https://docs.tamara.co), buy now pay later. The flow:
 *
 *   1. charge()         POST /checkout creates the order and a `checkout_url`; the customer is sent there.
 *   2. the customer approves at Tamara (order `approved`); Tamara sends a notification, signed with OUR notification token, and sends the customer back to our return URL.
 *   3. verify()         GET /merchants/orders/reference-id/{our reference}: the order's status. This is what is believed, never the notification body.
 *   4. capture()        an approved order is first AUTHORISED (POST /orders/{id}/authorise), then CAPTURED (POST /payments/capture): only then is the money taken and a refund possible.
 *   void()              POST /orders/{id}/cancel cancels an authorised order that will not be captured.
 *   refund()            POST /payments/simplified-refund/{id}, partial or full, of a captured order.
 *
 * Every call carries the merchant API token. Sandbox and production are different hosts (TAMARA_ENV).
 */
class TamaraPaymentGateway implements AsyncPaymentGateway
{
    use TalksToGateways;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'tamara';
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
        $country = strtoupper((string) ($this->config['country_code'] ?? 'SA'));
        $money = fn ($amount) => ['amount' => (float) GatewayAmount::format($amount, $payment->currency), 'currency' => strtoupper($payment->currency)];
        [$first, $last] = array_pad(explode(' ', $buyer['name'], 2), 2, '');
        $phone = $this->internationalPhone($buyer['phone'], $country);
        $address = ['first_name' => $first, 'last_name' => $last ?: $first, 'line1' => 'Online booking', 'city' => 'Riyadh', 'country_code' => $country, 'phone_number' => $phone];

        $response = $this->send('create checkout', 'POST', $this->url('/checkout'), fn () => $this->client((string) $this->config['api_token']), [
            'order_reference_id' => $payment->reference_id,
            'order_number' => $payment->reference_id,
            'total_amount' => $money($payment->amount),
            'description' => mb_substr('Booking '.$payment->reference_id, 0, 250),
            'country_code' => $country,
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'locale' => app()->getLocale() === 'ar' ? 'ar_SA' : 'en_US',
            // One line for the booking, at what the customer pays (the price after any coupon, with any fee), so the lines add up to the amount whatever the booking was made of.
            'items' => [[
                'reference_id' => (string) $reservation->uuid,
                'type' => 'Digital',
                'name' => mb_substr((string) ($reservation->activity?->title ?: 'Activity booking'), 0, 120),
                'sku' => 'ACT-'.$reservation->activity_id,
                'quantity' => 1,
                'unit_price' => $money($payment->amount),
                'discount_amount' => $money(0),
                'tax_amount' => $money(0),
                'total_amount' => $money($payment->amount),
            ]],
            'consumer' => array_filter(['first_name' => $first, 'last_name' => $last ?: $first, 'phone_number' => $phone, 'email' => $buyer['email']]),
            'billing_address' => $address,
            'shipping_address' => $address,
            'tax_amount' => $money(0),
            'shipping_amount' => $money(0),
            'discount' => ['name' => 'None', 'amount' => $money(0)],
            'merchant_url' => ['success' => $this->returnUrl($payment, 'success'), 'failure' => $this->returnUrl($payment, 'failure'), 'cancel' => $this->returnUrl($payment, 'cancel'), 'notification' => $this->webhookUrl()],
            'platform' => 'Marine Booking',
            'is_mobile' => (bool) ($params['is_mobile'] ?? false),
        ], PaymentGatewayUnavailableException::class);

        $json = $response->json() ?? [];
        $orderId = (string) data_get($json, 'order_id', '');
        $checkoutUrl = (string) data_get($json, 'checkout_url', '');

        if (! $response->successful() || $orderId === '' || $checkoutUrl === '') {
            $reason = $this->reasonFrom($response);
            $this->log('warning', 'checkout refused', ['payment' => $payment->reference_id, 'http' => $response->status(), 'reason' => $reason]);

            return ['provider_reference' => $payment->reference_id, 'status' => 'failed', 'redirect_url' => null, 'raw' => ['gateway' => 'tamara', 'reason' => $reason, 'code' => 'declined']];
        }

        return ['provider_reference' => $orderId, 'status' => 'pending', 'redirect_url' => $checkoutUrl, 'raw' => ['gateway' => 'tamara', 'order' => $orderId, 'checkout' => data_get($json, 'checkout_id'), 'checkout_url' => $checkoutUrl]];
    }

    public function handleWebhook(Request $request): array
    {
        $secret = (string) ($this->config['notification_token'] ?? '');
        $header = (string) $request->header('Authorization', '');

        // Tamara signs the notification (HS256) with the notification token we hold, and sends it as a bearer token or as `tamaraToken`. Either may be present (a proxy can add its
        // own Authorization header): the notification is accepted if ANY of them verifies. A signature that does not fit, an expired token, or no token at all is refused.
        $claims = null;

        foreach (array_filter([str_starts_with($header, 'Bearer ') ? substr($header, 7) : '', (string) $request->query('tamaraToken', '')]) as $candidate) {
            if ($claims = Jwt::verifyHs256($candidate, $secret)) {
                break;
            }
        }

        if ($claims === null) {
            $this->log('warning', 'notification refused: the token is missing, wrong or expired');

            throw new InvalidWebhookException('tamara', 'the token is missing, wrong or expired');
        }

        $orderId = (string) $request->input('order_id', '');
        $event = (string) $request->input('event_type', $request->input('order_status', ''));

        if ($orderId === '') {
            throw new InvalidWebhookException('tamara', 'it names no order');
        }

        return ['provider_reference' => $orderId, 'status' => 'pending', 'event_key' => "{$orderId}:{$event}", 'type' => $event, 'raw' => ['order' => $orderId, 'event' => $event, 'reference' => $request->input('order_reference_id')]];
    }

    public function verify(Payment $payment): array
    {
        $json = $this->order($payment);
        $gatewayStatus = strtolower((string) ($json['status'] ?? ''));

        $status = match ($gatewayStatus) {
            'approved', 'authorised' => 'authorized',
            'fully_captured', 'partially_captured', 'refunded', 'partially_refunded', 'fully_refunded', 'updated' => 'captured',
            'canceled', 'cancelled' => 'cancelled',
            'declined' => 'declined',
            'expired' => 'expired',
            default => 'pending',   // new
        };

        return [
            'status' => $status,
            'amount' => isset($json['total_amount']['amount']) ? (float) $json['total_amount']['amount'] : null,
            'currency' => isset($json['total_amount']['currency']) ? strtoupper((string) $json['total_amount']['currency']) : null,
            'reference' => $json['order_reference_id'] ?? null,
            'gateway_status' => $gatewayStatus,
            'raw' => ['gateway' => 'tamara', 'order' => $payment->payment_id, 'status' => $gatewayStatus],
        ];
    }

    public function capture(Payment $payment): array
    {
        $state = $this->verify($payment);

        if ($state['status'] === 'captured') {
            return ['captured' => true, 'raw' => ['gateway' => 'tamara', 'capture' => 'already']];
        }

        // An order the customer approved must be authorised before it can be captured.
        if ($state['gateway_status'] === 'approved') {
            $authorise = $this->send('authorise', 'POST', $this->url("/orders/{$payment->payment_id}/authorise"), fn () => $this->client((string) $this->config['api_token']));

            if (! $authorise->successful()) {
                $this->log('warning', 'authorise refused', ['payment' => $payment->reference_id, 'http' => $authorise->status(), 'reason' => $this->reasonFrom($authorise)]);

                return ['captured' => $this->verify($payment)['status'] === 'captured', 'raw' => ['gateway' => 'tamara', 'capture' => 'authorise refused', 'http' => $authorise->status()]];
            }
        }

        $response = $this->send('capture', 'POST', $this->url('/payments/capture'), fn () => $this->client((string) $this->config['api_token']), [
            'order_id' => $payment->payment_id,
            'total_amount' => ['amount' => (float) GatewayAmount::format($payment->amount, $payment->currency), 'currency' => strtoupper($payment->currency)],
            'shipping_info' => ['shipped_at' => now()->toIso8601String(), 'shipping_company' => 'Digital service (no shipping)'],
        ]);

        if ($response->successful()) {
            return ['captured' => true, 'raw' => ['gateway' => 'tamara', 'capture' => 'done', 'capture_id' => $response->json('capture_id')]];
        }

        $this->log('warning', 'capture refused', ['payment' => $payment->reference_id, 'http' => $response->status(), 'reason' => $this->reasonFrom($response)]);

        return ['captured' => $this->verify($payment)['status'] === 'captured', 'raw' => ['gateway' => 'tamara', 'capture' => 'refused', 'http' => $response->status()]];
    }

    public function void(Payment $payment): void
    {
        $state = $this->verify($payment);

        // Only an authorised (or approved) order can be cancelled; one the customer never approved expires by itself, and one already cancelled needs nothing.
        if ($state['status'] !== 'authorized') {
            return;
        }

        $response = $this->send('cancel', 'POST', $this->url("/orders/{$payment->payment_id}/cancel"), fn () => $this->client((string) $this->config['api_token']), [
            'total_amount' => ['amount' => (float) GatewayAmount::format($payment->amount, $payment->currency), 'currency' => strtoupper($payment->currency)],
        ]);

        if (! $response->successful()) {
            $this->log('warning', 'cancel refused', ['payment' => $payment->reference_id, 'http' => $response->status(), 'reason' => $this->reasonFrom($response)]);
        }
    }

    public function refund(Payment $payment, ?float $amount = null): array
    {
        $remaining = round((float) $payment->amount - (float) $payment->refunded_amount, 2);
        $amount = $amount === null ? $remaining : min($amount, $remaining);

        $response = $this->send('refund', 'POST', $this->url("/payments/simplified-refund/{$payment->payment_id}"), fn () => $this->client((string) $this->config['api_token']), [
            'total_amount' => ['amount' => (float) GatewayAmount::format($amount, $payment->currency), 'currency' => strtoupper($payment->currency)],
            'comment' => 'Booking cancelled ('.$payment->reference_id.')',
        ]);

        if (! $response->successful()) {
            $this->log('error', 'refund refused', ['payment' => $payment->reference_id, 'http' => $response->status(), 'reason' => $this->reasonFrom($response)]);

            throw new PaymentGatewayException("tamara refused the refund: {$this->reasonFrom($response)}");
        }

        return ['status' => 'refunded', 'refunded_amount' => round($amount, 2), 'raw' => ['gateway' => 'tamara', 'refund' => 'done', 'amount' => $amount, 'refund_id' => $response->json('refund_id')]];
    }

    /** @return array<string, mixed> */
    private function order(Payment $payment): array
    {
        $response = $this->send('retrieve order', 'GET', $this->url('/merchants/orders/reference-id/'.rawurlencode($payment->reference_id)), fn () => $this->client((string) $this->config['api_token']));

        if (! $response->successful()) {
            $this->log('error', 'order could not be read', ['payment' => $payment->reference_id, 'http' => $response->status()]);

            throw new PaymentGatewayException("tamara could not tell the state of order {$payment->reference_id} (HTTP {$response->status()})");
        }

        return (array) $response->json();
    }

    private function url(string $path): string
    {
        $base = $this->config['base_url'] ?: (($this->config['environment'] ?? 'sandbox') === 'production' ? 'https://api.tamara.co' : 'https://api-sandbox.tamara.co');

        return rtrim((string) $base, '/').$path;
    }
}
