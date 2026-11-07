<?php

namespace App\Services\ProviderApp;

use App\Enums\CancellationSource;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\ProviderApiException;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReservationService;
use App\Support\ProviderApi\ErrorCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A provider cancels a booking that is already CONFIRMED (paid). Bookings confirm themselves when the payment succeeds, so there is nothing
 * to accept or decline; what a provider can do is cancel, and then it is the provider's doing, not the customer's:
 *
 *   - a reason is mandatory;
 *   - the cancellation is recorded as made by the provider, and by which account;
 *   - the customer is refunded IN FULL, whatever they paid, and told it was the provider who cancelled;
 *   - it is all or nothing: if the refund cannot be made the booking stays confirmed, so a customer is never left cancelled and unpaid back.
 *
 * Only a confirmed booking whose trip day has not passed. A booking of another provider is "not found", like one that does not exist.
 */
class ProviderBookingCancellation
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PaymentService $payments,
    ) {}

    /** @return array{reservation: Reservation, refunded: float} */
    public function cancel(Provider $provider, string $bookingId, string $reason, User $actor): array
    {
        $id = ctype_digit($bookingId) ? (int) $bookingId : 0;

        return DB::transaction(function () use ($provider, $id, $reason, $actor) {
            // Locked, so two cancellations of the same booking cannot both refund it.
            $reservation = Reservation::query()->whereHas('activity', fn ($q) => $q->where('provider_id', $provider->id))->lockForUpdate()->find($id);

            if (! $reservation) {
                throw ProviderApiException::notFound('Booking not found.');
            }

            if ($reservation->status !== ReservationStatus::Confirmed) {
                throw ProviderApiException::conflict("Only a confirmed booking can be cancelled by the provider; this one is {$reservation->status->value}.", ErrorCode::INVALID_STATUS_TRANSITION);
            }

            if ($reservation->reservation_date->lt(Carbon::today())) {
                throw ProviderApiException::conflict('This trip has already taken place, so the booking can no longer be cancelled.', ErrorCode::INVALID_STATUS_TRANSITION);
            }

            $refunded = $this->refundEverything($reservation);
            $reservation = $this->reservations->cancel($reservation, $reason, CancellationSource::Provider, $actor->id);

            return ['reservation' => $reservation, 'refunded' => $refunded];
        });
    }

    /** Refunds what remains of every payment of the booking. Any failure aborts the whole cancellation (the transaction rolls back). */
    private function refundEverything(Reservation $reservation): float
    {
        $total = 0.0;

        $payments = $reservation->payments()->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value])->lockForUpdate()->get();

        foreach ($payments as $payment) {
            $remaining = round((float) $payment->amount - (float) $payment->refunded_amount, 2);

            if ($remaining <= 0) {
                continue;
            }

            try {
                $this->payments->refund($payment, $remaining);
            } catch (Throwable $e) {
                report($e);

                throw new ProviderApiException('The refund could not be processed, so the booking was NOT cancelled. Please try again.', 502, ErrorCode::REFUND_FAILED);
            }

            $total += $remaining;
        }

        return round($total, 2);
    }
}
