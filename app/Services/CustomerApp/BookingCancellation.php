<?php

namespace App\Services\CustomerApp;

use App\Enums\CancellationSource;
use App\Enums\PaymentStatus;
use App\Exceptions\ProviderApiException;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReservationService;
use App\Support\ProviderApi\ErrorCode;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A customer cancelling their own booking. What comes back is decided here, by the cancellation policy, from what was actually paid: the app never
 * says how much. It happens all together or not at all (if the refund fails the booking is NOT cancelled), under a lock so two taps cannot refund twice.
 * Cancelling gives the coupon use and the capacity back (they follow the booking's status), and the wallet follows the refund by itself.
 */
class BookingCancellation
{
    public function __construct(
        private readonly CancellationPolicy $policy,
        private readonly BookingPresenter $presenter,
        private readonly PaymentService $payments,
        private readonly ReservationService $reservations,
    ) {}

    /** @return array{reservation: Reservation, refund_amount: float} */
    public function cancel(Customer $customer, User $user, string $id, ?string $reason): array
    {
        return DB::transaction(function () use ($customer, $user, $id, $reason) {
            $reservation = (ctype_digit($id) ? Reservation::where('customer_id', $customer->id)->with('schedulePeriod')->lockForUpdate()->find((int) $id) : null)
                ?? throw ProviderApiException::notFound('Booking not found.');

            if (! $this->presenter->canCancel($reservation)) {
                $shown = $this->presenter->status($reservation);
                throw ProviderApiException::conflict($shown === 'upcoming' ? 'This trip has already started, so the booking can no longer be cancelled.' : "This booking cannot be cancelled: it is {$shown}.", ErrorCode::BOOKING_NOT_CANCELLABLE);
            }

            $refunded = $reservation->status->value === 'confirmed' ? $this->refund($reservation, $this->policy->refundPercent($this->presenter->startsAt($reservation))) : 0.0;

            return ['reservation' => $this->reservations->cancel($reservation, $reason, CancellationSource::Customer, $user->id), 'refund_amount' => $refunded];
        });
    }

    /** Refunds that share of what is still refundable on each succeeded payment. */
    private function refund(Reservation $reservation, float $percent): float
    {
        $total = 0.0;
        $payments = $reservation->payments()->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value])->lockForUpdate()->get();

        foreach ($payments as $payment) {
            $amount = round(round((float) $payment->amount - (float) $payment->refunded_amount, 2) * $percent / 100, 2);

            if ($amount <= 0) {
                continue;
            }

            try {
                $this->payments->refund($payment, $amount);
            } catch (Throwable $e) {
                report($e);
                throw new ProviderApiException('The refund could not be processed, so the booking was NOT cancelled. Please try again.', 502, ErrorCode::REFUND_FAILED);
            }

            $total += $amount;
        }

        return round($total, 2);
    }
}
