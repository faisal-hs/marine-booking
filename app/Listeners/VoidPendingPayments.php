<?php

namespace App\Listeners;

use App\Events\ReservationCancelled;
use App\Events\ReservationExpired;
use App\Services\PaymentService;

/**
 * A booking that is cancelled, or whose hold ran out, no longer wants its open checkouts: they are closed at the gateway (an approval is voided) and here, so a customer who still
 * pays is not charged for a booking that is gone.
 */
class VoidPendingPayments
{
    public function __construct(private readonly PaymentService $payments) {}

    public function handle(ReservationCancelled|ReservationExpired $event): void
    {
        $this->payments->voidPending($event->reservation, $event instanceof ReservationCancelled ? 'cancelled' : 'expired');
    }
}
