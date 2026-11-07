<?php

namespace App\Listeners\Wallet;

use App\Events\PaymentRefunded;
use App\Services\Wallet\LedgerService;

/** A payment was refunded: if its booking is already in the provider's wallet, the ledger must say so too. */
class SyncWalletOnRefund
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function handle(PaymentRefunded $event): void
    {
        $reservation = $event->payment->reservation;

        if ($reservation) {
            $this->ledger->syncBooking($reservation);
        }
    }
}
