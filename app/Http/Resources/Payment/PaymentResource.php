<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing — deliberately omits payment_id/provider_reference/
 * raw_payload (internal gateway identifiers and debugging payloads with
 * no customer-facing meaning). The admin-facing equivalent (Step 4)
 * exposes all of it, matching the established
 * PublicActivityResource-vs-ActivityResource / ReservationResource-vs-
 * AdminReservationResource separation pattern.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'reference_id' => $this->reference_id,
            'reservation' => [
                'id' => $this->reservation->id,
                'uuid' => $this->reservation->uuid,
            ],
            'gateway' => $this->gateway,
            'status' => $this->status->value,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'failure_reason' => $this->failure_reason,
            // Why it did not succeed: declined, cancelled, expired, mismatch, late_payment, error (null otherwise).
            'failure_code' => $this->failure_code,
            // Where the customer pays (Tabby, Tamara) and until when; null for a payment with no checkout, and once it is over.
            'checkout_url' => $this->status === \App\Enums\PaymentStatus::Pending ? $this->checkout_url : null,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'refunded_amount' => (float) $this->refunded_amount,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
