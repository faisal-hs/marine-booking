<?php

namespace App\Services;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Events\PaymentFailed;
use App\Events\PaymentRefunded;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;


class PaymentService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected PaymentRepositoryInterface $repository,
        protected ReservationService $reservationService,
    ) {}

    /**
     * @return array{payment: Payment, redirect_url: ?string}
     */
    public function initiate(Reservation $reservation, array $params = []): array
    {
        if ($reservation->status !== ReservationStatus::Pending) {
            throw ValidationException::withMessages([
                'reservation' => ['Only a pending reservation can be paid for.'],
            ]);
        }

        if ($reservation->hold_expires_at !== null && $reservation->hold_expires_at->isPast()) {
            throw ValidationException::withMessages([
                'reservation' => ['This reservation hold has expired. Please create a new reservation.'],
            ]);
        }

        $payment = $this->repository->create([
            'reservation_id' => $reservation->id,
            'gateway' => config('payments.default'),
            'amount' => $reservation->total,
            'currency' => $reservation->currency,
            'status' => PaymentStatus::Pending->value,
        ]);

        try {
            $result = $this->gateway->charge($payment, $params);
        } catch (PaymentGatewayUnavailableException $e) {
            // The gateway was refused before anything was sent, so the pending row
            // created above describes a charge that was never attempted. Remove it
            // rather than leave an orphan behind for every refused attempt.
            $this->repository->delete($payment);

            throw $e;
        }

        $payment->update([
            // For the mock gateway these are the same value; a real
            // gateway may return genuinely different ids for "the
            // checkout session" vs "the eventual charge" — both are
            // stored so either can be used to query the gateway later.
            'provider_reference' => $result['provider_reference'],
            'payment_id' => $result['provider_reference'],
            'raw_payload' => $result['raw'],
        ]);

        $payment = $payment->fresh();

        $payment = match ($result['status']) {
            'succeeded' => $this->markSucceeded($payment),
            'failed' => $this->markFailed($payment, $result['raw']['reason'] ?? 'Payment failed'),
            default => $payment, // 'pending' — async, waiting for the webhook
        };

        return ['payment' => $payment, 'redirect_url' => $result['redirect_url'] ?? null];
    }

    /**
     * Idempotent — a webhook can legitimately arrive more than once (most
     * gateways don't guarantee exactly-once delivery), and a sync charge
     * result plus a later webhook for the same payment must not double-
     * confirm the reservation.
     */
    public function markSucceeded(Payment $payment, array $extraRawPayload = []): Payment
    {
        if ($payment->status === PaymentStatus::Succeeded) {
            return $payment;
        }

        $payment->update([
            'status' => PaymentStatus::Succeeded->value,
            'paid_at' => now(),
            'raw_payload' => array_merge($payment->raw_payload ?? [], $extraRawPayload),
        ]);

        $this->reservationService->confirmPayment($payment->reservation);

        return $payment->fresh();
    }

    /**
     * The reservation deliberately stays 'pending' here — per the
     * established design decision, a failed payment attempt lets the
     * customer retry before the hold expires, rather than immediately
     * failing the whole reservation.
     */
    public function markFailed(Payment $payment, string $reason): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return $payment; // never overwrite an already-settled payment
        }

        $payment->update([
            'status' => PaymentStatus::Failed->value,
            'failure_reason' => $reason,
        ]);

        $payment = $payment->fresh();

        event(new PaymentFailed($payment));

        return $payment;
    }

    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        if (! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Only a succeeded (or partially refunded) payment can be refunded.'],
            ]);
        }

        $result = $this->gateway->refund($payment, $amount);

        $newRefundedTotal = round((float) $payment->refunded_amount + $result['refunded_amount'], 2);
        $isFullyRefunded = $newRefundedTotal >= (float) $payment->amount;

        $payment->update([
            'status' => $isFullyRefunded ? PaymentStatus::Refunded->value : PaymentStatus::PartiallyRefunded->value,
            'refunded_amount' => $newRefundedTotal,
        ]);

        $payment = $payment->fresh();

        event(new PaymentRefunded($payment));

        return $payment;
    }

    /**
     * The async confirmation path: a webhook arrives independently of
     * any HTTP request the customer is part of. Signature verification
     * happens entirely inside the gateway driver (handleWebhook()) —
     * this method never sees or trusts raw request data directly.
     */
    public function handleWebhook(Request $request): Payment
    {
        $result = $this->gateway->handleWebhook($request);

        $payment = $this->repository->findByProviderReference($result['provider_reference']);

        if (! $payment) {
            throw ValidationException::withMessages([
                'provider_reference' => ['No payment found for this reference.'],
            ]);
        }

        return match ($result['status']) {
            'succeeded' => $this->markSucceeded($payment, $result['raw']),
            'failed' => $this->markFailed($payment, $result['raw']['reason'] ?? 'Payment failed via webhook'),
            default => $payment,
        };
    }
}
