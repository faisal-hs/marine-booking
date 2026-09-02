<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Separate from the customer-facing PaymentResource — exposes the
 * gateway's internal identifiers (payment_id, provider_reference) and
 * raw_payload, none of which belong in the customer's own view, matching
 * the ReservationResource-vs-AdminReservationResource precedent exactly.
 */
class AdminPaymentResource extends JsonResource
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
                'reservation_date' => $this->reservation->reservation_date->toDateString(),
            ],
            'customer' => [
                'id' => $this->reservation->customer->id,
                'uuid' => $this->reservation->customer->uuid,
                'name' => $this->reservation->customer->user->name,
                'email' => $this->reservation->customer->user->email,
            ],
            'activity' => [
                'id' => $this->reservation->activity->id,
                'title' => $this->reservation->activity->title,
            ],
            'gateway' => $this->gateway,
            'payment_id' => $this->payment_id,
            'provider_reference' => $this->provider_reference,
            'status' => $this->status->value,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'failure_reason' => $this->failure_reason,
            'refunded_amount' => (float) $this->refunded_amount,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'raw_payload' => $this->raw_payload,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
