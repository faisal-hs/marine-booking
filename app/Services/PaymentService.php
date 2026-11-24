<?php

namespace App\Services;

use App\Contracts\Payments\AsyncPaymentGateway;
use App\Contracts\Payments\PaymentGatewayInterface;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Events\PaymentFailed;
use App\Events\PaymentRefunded;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Reservation;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money\GatewayAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected PaymentRepositoryInterface $repository,
        protected ReservationService $reservationService,
    ) {}

    protected function gateways(): PaymentGatewayManager
    {
        return app(PaymentGatewayManager::class);
    }

    /** The gateway a payment was made through: the bound default one, or the online gateway it names. */
    protected function gatewayFor(string $name): PaymentGatewayInterface
    {
        return $name === config('payments.default') ? $this->gateway : $this->gateways()->resolve($name);
    }

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

        // The method the customer chose decides the gateway: Tabby and Tamara have their own, every other method goes to the default one.
        $method = $params['payment_method'] ?? $reservation->payment_method;
        $gatewayName = $this->gateways()->gatewayNameForMethod($method);
        $gateway = $this->gatewayFor($gatewayName);

        if ($gateway instanceof AsyncPaymentGateway) {
            $this->assertMethodUsable($reservation, (string) $method, $gateway);

            return $this->initiateOnline($reservation, $gatewayName, $gateway, $params);
        }

        return $this->initiateOnGateway($reservation, $gatewayName, $gateway, $params);
    }

    /**
     * The original path, for the default gateway (card, apple_pay, mada; the mock): a payment row, one charge, a result that is either known now or arrives by webhook.
     *
     * @return array{payment: Payment, redirect_url: ?string}
     */
    protected function initiateOnGateway(Reservation $reservation, string $gatewayName, PaymentGatewayInterface $gateway, array $params): array
    {
        // A customer who chooses another way to pay leaves no checkout open at Tabby or Tamara: if they approved it later, it would be captured as well, and the booking would be paid twice.
        foreach ($reservation->payments()->where('status', PaymentStatus::Pending->value)->where('gateway', '!=', $gatewayName)->get() as $open) {
            if ($this->gatewayFor($open->gateway) instanceof AsyncPaymentGateway) {
                $this->closeOnline($open, 'cancelled');
            }
        }

        $payment = $this->repository->create([
            'reservation_id' => $reservation->id,
            'gateway' => $gatewayName,
            'amount' => $reservation->total,
            'currency' => $reservation->currency,
            'status' => PaymentStatus::Pending->value,
        ]);

        try {
            $result = $gateway->charge($payment, $params);
        } catch (PaymentGatewayUnavailableException $e) {
            // The gateway was refused before anything was sent, so the pending row created above describes a charge that was never attempted. Remove it
            // rather than leave an orphan behind for every refused attempt.
            $this->repository->delete($payment);

            throw $e;
        }

        $payment->update([
            // For the mock gateway these are the same value; a real gateway may return genuinely different ids for "the checkout session" vs "the eventual
            // charge" — both are stored so either can be used to query the gateway later.
            'provider_reference' => $result['provider_reference'],
            'payment_id' => $result['provider_reference'],
            // Where the customer pays, when the gateway sends them somewhere: kept, so the same booking answered again (a retry) still says where.
            'checkout_url' => $result['status'] === 'pending' ? ($result['redirect_url'] ?? null) : null,
            'raw_payload' => $result['raw'],
        ]);
        $this->assertStored($payment, 'checkout_url', $result['status'] === 'pending' ? ($result['redirect_url'] ?? null) : null);

        $payment = $payment->fresh();

        $payment = match ($result['status']) {
            'succeeded' => $this->markSucceeded($payment),
            'failed' => $this->markFailed($payment, $result['raw']['reason'] ?? 'Payment failed'),
            default => $payment, // 'pending' — async, waiting for the webhook
        };

        return ['payment' => $payment, 'redirect_url' => $result['redirect_url'] ?? null];
    }

    /**
     * A payment at an online gateway (Tabby, Tamara): one checkout per booking at a time. The booking is locked while this decides, so two taps on "Pay" cannot make two checkouts
     * (the second gets the first's), a booking that is paid cannot be paid again, and a checkout that is dead (expired, or for another method) is closed before a new one starts.
     *
     * @return array{payment: Payment, redirect_url: ?string}
     */
    protected function initiateOnline(Reservation $reservation, string $gatewayName, AsyncPaymentGateway $gateway, array $params): array
    {
        return DB::transaction(function () use ($reservation, $gatewayName, $gateway, $params) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->payments()->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])->exists()) {
                throw ValidationException::withMessages(['reservation' => ['This booking has already been paid.']]);
            }

            foreach ($locked->payments()->where('status', PaymentStatus::Pending->value)->get() as $open) {
                $live = $open->gateway === $gatewayName && $open->checkout_url && ($open->expires_at === null || $open->expires_at->isFuture());

                if ($live) {
                    // The checkout this customer already has: they get it again.
                    return ['payment' => $open, 'redirect_url' => $open->checkout_url];
                }

                $this->closeOnline($open, $open->gateway === $gatewayName ? 'expired' : 'cancelled');
            }

            $payment = $this->repository->create([
                'reservation_id' => $locked->id,
                'gateway' => $gatewayName,
                'amount' => $locked->total,
                'currency' => $locked->currency,
                'status' => PaymentStatus::Pending->value,
                'expires_at' => $locked->hold_expires_at,
            ]);

            try {
                $result = $gateway->charge($payment, $params);
            } catch (PaymentGatewayUnavailableException $e) {
                $this->repository->delete($payment);

                throw $e;
            }

            $payment->update([
                'provider_reference' => $result['provider_reference'],
                'payment_id' => $result['provider_reference'],
                'checkout_url' => $result['redirect_url'] ?? null,
                'gateway_status' => $result['status'] === 'pending' ? 'pending' : null,
                'raw_payload' => $result['raw'],
            ]);

            $this->assertStored($payment, 'checkout_url', $result['redirect_url'] ?? null);
            $payment = $payment->fresh();

            if ($result['status'] === 'failed') {
                $payment = $this->markFailed($payment, (string) ($result['raw']['reason'] ?? 'Payment failed'), (string) ($result['raw']['code'] ?? 'declined'));
            }

            $this->log('info', 'payment started', ['gateway' => $gatewayName, 'payment' => $payment->reference_id, 'booking' => $locked->uuid, 'status' => $payment->status->value]);

            return ['payment' => $payment, 'redirect_url' => $payment->status === PaymentStatus::Pending ? $payment->checkout_url : null];
        });
    }

    /**
     * What a gateway just told us must be what was saved. Laravel silently drops an attribute the model does not allow, so a Payment model (or payments table) that is older than this
     * code would lose the checkout address with no error at all: the booking would answer `redirect_url: null` and the customer could not pay. This turns that into a loud failure.
     */
    protected function assertStored(Payment $payment, string $attribute, ?string $expected): void
    {
        if ($expected !== null && $payment->fresh()?->{$attribute} !== $expected) {
            Log::critical("[payments] {$attribute} was not saved: app/Models/Payment.php or the payments table is out of date. Copy the whole of repo-sync-28 and run php artisan migrate; php artisan payments:check says what is wrong.");

            throw new \LogicException("The payment's {$attribute} was not saved: app/Models/Payment.php or the payments table is out of date (run php artisan migrate and copy app/Models/Payment.php and app/Models/PaymentEvent.php from the package; php artisan payments:check says what is wrong).");
        }
    }

    /** The method must be one the provider accepts for this activity, the gateway must take the currency, and the booking must be worth paying for. */
    protected function assertMethodUsable(Reservation $reservation, string $method, AsyncPaymentGateway $gateway): void
    {
        if (in_array($method, ['tabby', 'tamara'], true) && ! ($reservation->activity?->{'allows_'.$method})) {
            throw ValidationException::withMessages(['payment_method' => [ucfirst($method).' is not accepted for this activity.']]);
        }

        if (! in_array(strtoupper((string) $reservation->currency), $gateway->currencies(), true)) {
            throw ValidationException::withMessages(['payment_method' => [ucfirst($method).' does not take payments in '.strtoupper((string) $reservation->currency).'.']]);
        }

        if ((float) $reservation->total <= 0) {
            throw ValidationException::withMessages(['reservation' => ['There is nothing to pay for this booking.']]);
        }
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

        // Only a payment that is STILL pending is settled, and only the request that settles it confirms the booking (see ReservationService::confirmPayment).
        $settled = Payment::whereKey($payment->id)->where('status', PaymentStatus::Pending->value)->update([
            'status' => PaymentStatus::Succeeded->value,
            'paid_at' => now(),
            'failure_reason' => null,
            'failure_code' => null,
            'raw_payload' => json_encode(array_merge($payment->raw_payload ?? [], $extraRawPayload)),
            'updated_at' => now(),
        ]);

        if ($settled === 0) {
            return $payment->fresh();
        }

        $this->reservationService->confirmPayment($payment->reservation);

        return $payment->fresh();
    }

    /**
     * The reservation deliberately stays 'pending' here — per the
     * established design decision, a failed payment attempt lets the
     * customer retry before the hold expires, rather than immediately
     * failing the whole reservation.
     *
     * `$code` says why: declined, cancelled, expired, mismatch, late_payment, error. `$notify` false closes the payment quietly (no "payment failed" notification): for a checkout
     * that was cancelled or replaced, which the customer already knows about.
     */
    public function markFailed(Payment $payment, string $reason, string $code = 'declined', bool $notify = true): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return $payment; // never overwrite an already-settled payment
        }

        $payment->update([
            'status' => PaymentStatus::Failed->value,
            'failure_reason' => $reason,
            'failure_code' => $code,
        ]);

        $payment = $payment->fresh();

        if ($notify) {
            event(new PaymentFailed($payment));
        }

        return $payment;
    }

    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        if (! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Only a succeeded (or partially refunded) payment can be refunded.'],
            ]);
        }

        $result = $this->gatewayFor($payment->gateway)->refund($payment, $amount);

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
     *
     * For an online gateway (Tabby, Tamara) the notification only says WHICH payment to look at: it is checked with the gateway (`reconcile`), and nothing is
     * settled on what the notification itself says. The same notification arriving twice is answered from the first.
     */
    public function handleWebhook(Request $request, ?string $gatewayName = null): Payment
    {
        $gatewayName ??= (string) config('payments.default');
        $gateway = $this->gatewayFor($gatewayName);
        $result = $gateway->handleWebhook($request);

        $payment = $this->repository->findByProviderReference($result['provider_reference']);

        if (! $payment || $payment->gateway !== $gatewayName) {
            throw ValidationException::withMessages([
                'provider_reference' => ['No payment found for this reference.'],
            ]);
        }

        if ($gateway instanceof AsyncPaymentGateway) {
            return $this->settleEvent($payment, $gatewayName, $result);
        }

        return match ($result['status']) {
            'succeeded' => $this->markSucceeded($payment, $result['raw']),
            'failed' => $this->markFailed($payment, $result['raw']['reason'] ?? 'Payment failed via webhook'),
            default => $payment,
        };
    }

    /** Records a notification once, and settles from it; the same one again answers with where the payment now stands. */
    protected function settleEvent(Payment $payment, string $gatewayName, array $result): Payment
    {
        try {
            $event = PaymentEvent::firstOrCreate(['gateway' => $gatewayName, 'event_key' => (string) $result['event_key']], ['payment_id' => $payment->id, 'type' => $result['type'] ?? null, 'payload' => $result['raw']]);
        } catch (UniqueConstraintViolationException) {
            $event = PaymentEvent::where(['gateway' => $gatewayName, 'event_key' => (string) $result['event_key']])->firstOrFail();
        }

        if ($event->result !== null) {
            $this->log('info', 'notification repeated', ['gateway' => $gatewayName, 'event' => $result['event_key']]);

            return $payment->fresh();
        }

        $payment = $this->reconcile($payment, 'webhook');

        // A notification is marked done only once the payment has SETTLED. One that found it still undecided (the gateway had not caught up) is looked at again when the gateway sends it again.
        if ($payment->status !== PaymentStatus::Pending) {
            $event->update(['result' => $payment->status->value]);
        }

        return $payment;
    }

    /**
     * THE ONE PLACE an online payment is settled: asked of the gateway (never believed from a notification), compared with what we asked for, and then:
     *   not decided      nothing; it is looked at again (the next notification, the customer's return, or `payments:reconcile`)
     *   declined / cancelled / expired   the payment fails, with that as its code
     *   approved         if the booking can still be paid (pending, hold not run out): the money is taken and the booking confirmed; if not, the approval is VOIDED, so nobody is charged
     *   already taken    if the booking can still be paid: it is confirmed; if not, the money is given back
     *
     * It runs with the payment locked, so two notifications (or a notification and the customer's return) cannot both settle it. A payment that already failed because the
     * booking lapsed is still looked at, so an approval that arrives late is voided and not left hanging.
     */
    public function reconcile(Payment $payment, string $trigger = 'reconcile'): Payment
    {
        $gateway = $this->gatewayFor($payment->gateway);

        if (! $gateway instanceof AsyncPaymentGateway) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $gateway, $trigger) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Paid (or paid and given back): nothing more to learn.
            if (in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
                return $payment;
            }

            $state = $gateway->verify($payment);
            $payment->update(['gateway_status' => $state['gateway_status'], 'raw_payload' => array_merge($payment->raw_payload ?? [], $state['raw'])]);
            $this->log('info', 'payment checked', ['gateway' => $payment->gateway, 'payment' => $payment->reference_id, 'trigger' => $trigger, 'state' => $state['status'], 'gateway_status' => $state['gateway_status']]);

            $reservation = Reservation::whereKey($payment->reservation_id)->lockForUpdate()->first();
            $payable = $reservation && $reservation->status === ReservationStatus::Pending && ($reservation->hold_expires_at === null || $reservation->hold_expires_at->isFuture());
            $lapse = $reservation && $reservation->status === ReservationStatus::Cancelled ? 'cancelled' : 'expired';
            $failed = $payment->status === PaymentStatus::Failed;

            // What the gateway holds must be what we asked for, or nothing is taken.
            if (in_array($state['status'], ['authorized', 'captured'], true) && ! $this->matches($payment, $state)) {
                $this->log('critical', 'payment does not match what was asked for', ['payment' => $payment->reference_id, 'asked' => GatewayAmount::format($payment->amount, $payment->currency).' '.$payment->currency,
                    'held' => ($state['amount'] ?? '?').' '.($state['currency'] ?? '?'), 'reference' => $state['reference']]);

                return $this->undo($payment, $gateway, $state, 'mismatch', 'The amount, currency or reference at the gateway is not what was asked for.');
            }

            return match ($state['status']) {
                'declined' => $this->markFailed($payment, 'The gateway declined the payment.', 'declined'),
                'cancelled' => $this->markFailed($payment, 'The payment was cancelled.', 'cancelled', notify: false),
                'expired' => $this->markFailed($payment, 'The payment expired before it was completed.', 'expired'),
                'authorized' => $this->settleApproved($payment, $gateway, $state, $payable && ! $failed, $lapse),
                'captured' => $this->settleCaptured($payment, $gateway, $state, $payable && ! $failed, $lapse),
                default => $payment,   // pending: the customer has not decided
            };
        });
    }

    /** What the gateway holds is what we asked for. */
    protected function matches(Payment $payment, array $state): bool
    {
        return GatewayAmount::same($state['amount'], $payment->amount, $payment->currency)
            && strtoupper((string) $state['currency']) === strtoupper($payment->currency)
            && ($state['reference'] === null || $state['reference'] === $payment->reference_id);
    }

    /** The customer approved. If the booking can still be paid the money is taken and the booking confirmed; if not the approval is voided. */
    protected function settleApproved(Payment $payment, AsyncPaymentGateway $gateway, array $state, bool $payable, string $lapse): Payment
    {
        if (! $payable) {
            return $this->undo($payment, $gateway, $state, $lapse === 'cancelled' ? 'cancelled' : 'late_payment', 'The booking was no longer waiting for payment when it was approved.');
        }

        $capture = $gateway->capture($payment);

        if (! $capture['captured']) {
            // Not taken (the gateway refused for now): the payment stays pending, to be looked at again. Nothing is confirmed that was not paid.
            $this->log('error', 'approved but not captured', ['payment' => $payment->reference_id]);

            return $payment;
        }

        return $this->succeed($payment, $gateway, $capture['raw']);
    }

    /** The money is taken (by us, or by the gateway). Confirmed if the booking can still be paid; otherwise given back. */
    protected function settleCaptured(Payment $payment, AsyncPaymentGateway $gateway, array $state, bool $payable, string $lapse): Payment
    {
        if (! $payable) {
            return $this->undo($payment, $gateway, $state, $lapse === 'cancelled' ? 'cancelled' : 'late_payment', 'The money was taken after the booking was no longer waiting for payment.');
        }

        return $this->succeed($payment, $gateway, $state['raw']);
    }

    /** Succeeds the payment and confirms the booking; if the booking cannot be confirmed after all (it lapsed a moment ago), the money is given back. */
    protected function succeed(Payment $payment, AsyncPaymentGateway $gateway, array $raw): Payment
    {
        try {
            return $this->markSucceeded($payment, $raw);
        } catch (ValidationException $e) {
            $payment->update(['status' => PaymentStatus::Pending->value, 'paid_at' => null]);

            return $this->undo($payment->fresh(), $gateway, ['status' => 'captured'], 'late_payment', 'The booking could not be confirmed: '.collect($e->errors())->flatten()->first());
        }
    }

    /**
     * Nobody is left charged for a booking that did not happen: an approval that was not captured is VOIDED, money that was taken is REFUNDED in full, and the payment is
     * closed with the reason. (A refund of a payment the customer never really completed does not send the "payment refunded" notification, which is for refunds of bookings.)
     */
    protected function undo(Payment $payment, AsyncPaymentGateway $gateway, array $state, string $code, string $reason): Payment
    {
        $taken = ($state['status'] ?? null) === 'captured';

        if ($taken) {
            $gateway->refund($payment, (float) $payment->amount);
            $payment->update(['status' => PaymentStatus::Refunded->value, 'refunded_amount' => $payment->amount, 'failure_code' => $code, 'failure_reason' => $reason]);
            $this->log('warning', 'money taken for a booking that did not happen was given back', ['payment' => $payment->reference_id, 'code' => $code]);

            return $payment->fresh();
        }

        $gateway->void($payment);

        if ($payment->status === PaymentStatus::Pending) {
            return $this->markFailed($payment, $reason, $code, notify: false);
        }

        $payment->update(['failure_code' => $payment->failure_code ?: $code]);
        $this->log('warning', 'a late approval was voided', ['payment' => $payment->reference_id, 'code' => $code]);

        return $payment->fresh();
    }

    /**
     * The booking is cancelled or has expired: every checkout still open for it is closed, at the gateway (an approval is voided) and here. The customer who pays anyway, later,
     * is not charged: see reconcile().
     */
    public function voidPending(Reservation $reservation, string $code): void
    {
        foreach ($reservation->payments()->where('status', PaymentStatus::Pending->value)->get() as $payment) {
            $this->closeOnline($payment, $code);
        }
    }

    /** Closes one open checkout: voided at the gateway when it is an online one (best effort: the reconciler and a late webhook void it again), failed here with the reason. */
    protected function closeOnline(Payment $payment, string $code): void
    {
        $gateway = $this->gatewayFor($payment->gateway);

        if ($gateway instanceof AsyncPaymentGateway && $payment->payment_id) {
            try {
                $gateway->void($payment);
            } catch (PaymentGatewayException $e) {
                $this->log('error', 'could not void at the gateway; it will be voided if it is ever approved', ['payment' => $payment->reference_id, 'code' => $code]);
            }
        }

        $this->markFailed($payment, $code === 'cancelled' ? 'The booking was cancelled.' : 'The payment time ran out.', $code, notify: false);
    }

    /**
     * The customer comes back from the gateway: success, cancel or failure. What they say is not believed: the payment is asked of the gateway. A customer who pressed "cancel"
     * while the gateway still shows nothing decided has their payment cancelled (and the checkout closed); anything the gateway says otherwise wins.
     */
    public function handleReturn(Payment $payment, string $outcome): Payment
    {
        $payment = $this->reconcile($payment, 'return:'.$outcome);

        if ($outcome === 'cancel' && $payment->status === PaymentStatus::Pending && $payment->gateway_status !== null) {
            $this->closeOnline($payment, 'cancelled');
            $payment = $payment->fresh();
        }

        return $payment;
    }

    /** One line in the payments log (ids and states, never a key, a token or a customer's details). */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel(config('payments.log_channel'))->{$level}("[payments] {$message}", $context);
    }
}
