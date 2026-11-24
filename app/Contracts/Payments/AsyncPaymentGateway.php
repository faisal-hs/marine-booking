<?php

namespace App\Contracts\Payments;

use App\Models\Payment;

/**
 * A gateway where the customer pays ELSEWHERE (a hosted checkout) and the result reaches us later: Tabby, Tamara. What the base contract promises is not enough for money that has
 * to be checked, taken and sometimes given back, so these gateways also answer:
 *
 *   verify   ask the gateway what REALLY happened to this payment. A notification is only a nudge to ask: nothing is settled on what it says.
 *   capture  take the money of a payment the customer has approved (a payment is a promise until it is captured, and cannot be refunded before).
 *   void     cancel a payment that has not been captured, so a customer who approved too late is not charged.
 *
 * All three are safe to call again: a payment already captured, closed or cancelled is answered, not an error.
 *
 * `handleWebhook()` (from the base contract) proves the notification came from the gateway and says which payment it is about; it returns
 * `['provider_reference' => ..., 'status' => 'pending', 'event_key' => ..., 'type' => ..., 'raw' => [...]]`, the status being only a hint.
 */
interface AsyncPaymentGateway extends PaymentGatewayInterface
{
    /** The gateway's name, as it is in `payments.gateway` and in the webhook URL. */
    public function name(): string;

    /** @return list<string> the currencies it takes */
    public function currencies(): array;

    /**
     * What the gateway says about the payment now. `status` is one of: pending (the customer has not decided), authorized (approved, not yet captured), captured (the money
     * is taken), declined, cancelled, expired. `amount`, `currency` and `reference` are what the gateway holds, for the caller to compare with what we asked for.
     *
     * @return array{status: string, amount: ?float, currency: ?string, reference: ?string, gateway_status: string, raw: array}
     */
    public function verify(Payment $payment): array;

    /** @return array{captured: bool, raw: array} */
    public function capture(Payment $payment): array;

    public function void(Payment $payment): void;
}
