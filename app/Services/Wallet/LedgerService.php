<?php

namespace App\Services\Wallet;

use App\Enums\ReservationStatus;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Support\Money\Commission;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The provider wallet ledger. Every movement of a provider's money is one signed row in wallet_entries; balances are sums of those rows.
 *
 * WHEN BOOKING MONEY ENTERS. When a booking is COMPLETED, three kinds of row are posted for it, each exactly once (their `reference` is unique):
 *
 *   booking_income  +total      what the customer paid for the booking (the gross)
 *   refund          -refunded   money given back to the customer, posted as it happens (a booking refunded later gets a new row)
 *   platform_fee    -commission the platform's commission on what the provider kept (total - refunded), under the provider's own terms
 *                               as they were when the first fee was posted (a later change of terms never rewrites the past)
 *
 * and all of them become available to withdraw `config('wallet.settlement_delay_days')` days after the END of the trip day.
 * syncBooking() is idempotent: it works out what the ledger SHOULD say about the booking and posts only the difference.
 *
 * Money is PHP floats rounded to two decimals at every step, as everywhere else on the platform.
 */
class LedgerService
{
    /** Post (or correct) the ledger rows of one booking. Does nothing for a booking that is not completed. */
    public function syncBooking(Reservation $reservation): void
    {
        if ($reservation->status !== ReservationStatus::Completed) {
            return;
        }

        $reservation->loadMissing('activity.provider');

        DB::transaction(function () use ($reservation) {
            $entries = WalletEntry::where('reservation_id', $reservation->id)->whereIn('type', WalletEntry::BOOKING_TYPES)->lockForUpdate()->get();
            $refunded = round((float) $reservation->payments()->sum('refunded_amount'), 2);

            foreach ($this->plan($reservation, $reservation->activity->provider, $entries, $refunded) as $row) {
                WalletEntry::firstOrCreate(['reference' => $row['reference']], $row);
            }
        });
    }

    /**
     * THE RULE: what the ledger should say about a completed booking, as the rows still missing. Pure: it reads nothing and writes nothing,
     * so the same rule serves the live service and the bulk seeder.
     *
     * @param  \Illuminate\Support\Collection<int, WalletEntry>  $entries  the booking's rows already in the ledger
     * @return list<array<string, mixed>>
     */
    public function plan(Reservation $reservation, Provider $provider, \Illuminate\Support\Collection $entries, float $refunded): array
    {
        // What the customer paid, and the part of it that is the provider's (the price after the coupon: the service fee and the VAT are not).
        $paid = round((float) $reservation->total, 2);
        $total = $reservation->providerAmount();
        $refunded = min($paid, round($refunded, 2));
        // A refund of some of what was paid takes back the same share of the provider's part (all of it when nothing but the price was charged).
        $refunded = $paid > 0 ? round($refunded * $total / $paid, 2) : 0.0;
        $income = $entries->firstWhere('type', WalletEntry::BOOKING_INCOME);

        // The same moment for every row of the booking, fixed by the first one.
        $common = [
            'provider_id' => $provider->id, 'reservation_id' => $reservation->id, 'currency' => $reservation->currency,
            'available_at' => $income?->available_at ?? $this->availableAt($reservation), 'occurred_on' => $reservation->reservation_date->toDateString(),
        ];
        $label = "Booking #{$reservation->id} - {$reservation->activity->title}";
        $rows = [];

        if (! $income) {
            $rows[] = $common + ['type' => WalletEntry::BOOKING_INCOME, 'amount' => $total, 'description' => $label, 'reference' => "booking:{$reservation->id}:income", 'meta' => ['gross' => $total, 'discount' => round((float) $reservation->discount, 2), 'service_fee' => round((float) $reservation->service_fee, 2), 'vat' => round((float) $reservation->tax, 2)]];
        }

        $postedRefund = round(-$entries->where('type', WalletEntry::REFUND)->sum('amount'), 2);
        if (abs($refunded - $postedRefund) >= 0.005) {
            $n = $entries->where('type', WalletEntry::REFUND)->count() + 1;
            $rows[] = $common + ['type' => WalletEntry::REFUND, 'amount' => round($postedRefund - $refunded, 2), 'description' => "Refund - Booking #{$reservation->id}",
                'reference' => "booking:{$reservation->id}:refund:{$n}", 'meta' => ['refunded_total' => $refunded]];
        }

        $fees = $entries->where('type', WalletEntry::PLATFORM_FEE);
        $terms = $fees->first()?->meta ?? ['commission_type' => $provider->commission_type, 'commission_value' => (float) $provider->commission_rate];
        $kept = round(max(0, $total - $refunded), 2);
        $desired = Commission::calculate((string) $terms['commission_type'], (float) $terms['commission_value'], $kept);
        $postedFee = round(-$fees->sum('amount'), 2);

        if (abs($desired - $postedFee) >= 0.005) {
            $n = $fees->count() + 1;
            $rows[] = $common + ['type' => WalletEntry::PLATFORM_FEE, 'amount' => round($postedFee - $desired, 2), 'description' => "Platform fee - Booking #{$reservation->id}",
                'reference' => "booking:{$reservation->id}:fee:{$n}", 'meta' => $terms + ['base' => $kept]];
        }

        return $rows;
    }

    /** Posts the completed bookings of one provider that are not in the ledger yet. Returns how many it posted. */
    public function syncProvider(Provider $provider): int
    {
        $query = Reservation::query()
            ->whereHas('activity', fn ($q) => $q->where('provider_id', $provider->id))
            ->where('status', ReservationStatus::Completed->value)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('wallet_entries as w')->whereColumn('w.reservation_id', 'reservations.id')->where('w.type', WalletEntry::BOOKING_INCOME));

        $count = 0;
        $query->chunkById(200, function ($reservations) use (&$count) {
            foreach ($reservations as $reservation) {
                $this->syncBooking($reservation);
                $count++;
            }
        });

        return $count;
    }

    /** Posts every completed booking that is not in the ledger yet (the nightly job, and a backfill). */
    public function syncAll(): int
    {
        $count = 0;
        Reservation::query()
            ->where('status', ReservationStatus::Completed->value)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('wallet_entries as w')->whereColumn('w.reservation_id', 'reservations.id')->where('w.type', WalletEntry::BOOKING_INCOME))
            ->chunkById(500, function ($reservations) use (&$count) {
                foreach ($reservations as $reservation) {
                    $this->syncBooking($reservation);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * The five balances of a provider at a moment (default now), all from the ledger.
     *
     *   available        what can be withdrawn now (booking money past its waiting period, minus what is reserved or already paid out)
     *   pending          booking money still inside its waiting period
     *   reserved         held for withdrawal requests that are pending or being processed
     *   total_earnings   everything the provider has earned, net of refunds and commission, whether or not it can be withdrawn yet
     *   total_withdrawn  paid out by an admin
     *
     * @return array{available: float, pending: float, reserved: float, total_earnings: float, total_withdrawn: float}
     */
    public function balances(Provider $provider, ?Carbon $at = null): array
    {
        $at ??= now();
        $entries = fn () => WalletEntry::query()->where('provider_id', $provider->id);

        return [
            'available' => round((float) $entries()->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $at))->sum('amount'), 2),
            'pending' => round((float) $entries()->where('available_at', '>', $at)->sum('amount'), 2),
            'reserved' => round((float) Withdrawal::where('provider_id', $provider->id)->whereIn('status', Withdrawal::RESERVING)->sum('amount'), 2),
            'total_earnings' => round((float) $entries()->whereNotIn('type', [WalletEntry::WITHDRAWAL_RESERVE, WalletEntry::WITHDRAWAL_RELEASE])->sum('amount'), 2),
            'total_withdrawn' => round((float) Withdrawal::where('provider_id', $provider->id)->where('status', Withdrawal::PAID)->sum('amount'), 2),
        ];
    }

    /** The end of the trip day plus the waiting period. */
    public function availableAt(Reservation $reservation): Carbon
    {
        return $reservation->reservation_date->copy()->endOfDay()->addDays(max(0, (int) config('wallet.settlement_delay_days')));
    }
}
