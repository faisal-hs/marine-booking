<?php

namespace Database\Seeders\Bulk;

use App\Models\Reservation;
use App\Models\WalletEntry;
use App\Services\Wallet\LedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The provider wallets: every completed booking is posted to its provider's ledger by the SAME rule the live system uses
 * (LedgerService::plan), so seeded money and real money cannot disagree: the booking's income, any refund, and the platform commission under the
 * provider's terms. Each booking is posted once; running it again adds nothing.
 * Volume at scale 1: one to three rows per completed booking (a booking with no commission and no refund gets only its income).
 * Prerequisites: BulkReservationSeeder (completed bookings), BulkPaymentSeeder (refunds).
 */
class BulkWalletEntrySeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('reservations', 'BulkReservationSeeder', fn ($q) => $q->where('status', 'completed'));
        $this->needs('payments', 'BulkPaymentSeeder');

        $ledger = app(LedgerService::class);
        $refunded = DB::table('payments')->where('refunded_amount', '>', 0)->selectRaw('reservation_id, SUM(refunded_amount) AS refunded')->groupBy('reservation_id')->pluck('refunded', 'reservation_id');

        Reservation::query()
            ->where('status', 'completed')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('wallet_entries as w')->whereColumn('w.reservation_id', 'reservations.id')->where('w.type', WalletEntry::BOOKING_INCOME))
            ->with('activity.provider')
            ->chunkById(1000, function ($reservations) use ($ledger, $refunded) {
                $this->insert('wallet_entries', (function () use ($reservations, $ledger, $refunded) {
                    foreach ($reservations as $reservation) {
                        foreach ($ledger->plan($reservation, $reservation->activity->provider, collect(), (float) ($refunded[$reservation->id] ?? 0)) as $row) {
                            // Posted the day after the trip, when the booking completed.
                            $posted = Carbon::parse($row['occurred_on'])->addDay()->setTime(1, 0);
                            $posted = $posted->greaterThan($this->now) ? $this->now->copy() : $posted;

                            yield array_merge($row, [
                                'uuid' => $this->uuid('wallet', $row['reference']),
                                'available_at' => $this->ts($row['available_at']),
                                'meta' => json_encode($row['meta']),
                                'created_at' => $this->ts($posted),
                                'updated_at' => $this->ts($posted),
                            ]);
                        }
                    }
                })());
            });
    }
}
