<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;

/**
 * Payments, derived from each reservation's own story so the two always agree:
 *  - confirmed and completed: a succeeded payment for the full total (6% after a failed first attempt; 1.5% of the
 *    completed ones later partly refunded);
 *  - cancelled AFTER being confirmed: it was paid, and 40% were refunded, 25% partly refunded, the rest not refunded;
 *  - cancelled without ever being confirmed: 20% had a failed attempt, the rest never paid;
 *  - expired: 35% had a failed attempt; pending: half have a payment in progress.
 * About one payment in four went through Tabby or Tamara (half each; the rest through the mock gateway), with what those gateways keep: their own reference, the status
 * they last reported, and for a failed one the reason (`declined`). BulkPaymentEventSeeder then gives each its notifications. In the platform currency (SAR by default).
 *
 * Volume at scale 1: about 132,000.
 * Prerequisites: BulkReservationSeeder.
 */
class BulkPaymentSeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $this->needs('reservations', 'BulkReservationSeeder');

        $have = array_flip(DB::table('payments')->distinct()->pluck('reservation_id')->all());

        $this->insert('payments', (function () use ($have) {
            $last = 0;
            while (true) {
                $reservations = DB::table('reservations')->where('id', '>', $last)->orderBy('id')->limit(2000)
                    ->get(['id', 'status', 'total', 'currency', 'confirmed_at', 'created_at']);

                if ($reservations->isEmpty()) {
                    return;
                }

                foreach ($reservations as $reservation) {
                    if (! isset($have[$reservation->id])) {
                        yield from $this->paymentsFor($reservation);
                    }
                }

                $last = $reservations->last()->id;
            }
        })());
    }

    /** @return \Generator<array<string, mixed>> */
    private function paymentsFor(object $r): \Generator
    {
        $total = (float) $r->total;
        $n = 0;

        $failedFirst = match ($r->status) {
            'confirmed', 'completed' => $this->stable("payment-retry-{$r->id}", 6),
            'cancelled' => $r->confirmed_at === null && $this->stable("payment-attempt-{$r->id}", 20),
            'expired' => $this->stable("payment-attempt-{$r->id}", 35),
            default => false,
        };

        if ($failedFirst) {
            yield $this->payment($r, ++$n, 'failed', $total, 0, null, $r->created_at, $this->pick($this->failureReasons()));
        }

        if ($r->status === 'pending' && $this->stable("payment-pending-{$r->id}", 50)) {
            yield $this->payment($r, ++$n, 'pending', $total, 0, null, $r->created_at, null);

            return;
        }

        if (! in_array($r->status, ['confirmed', 'completed'], true) && ! ($r->status === 'cancelled' && $r->confirmed_at !== null)) {
            return;
        }

        [$status, $refunded] = match (true) {
            $r->status === 'cancelled' => match ($this->weighted(['refunded' => 40, 'partial' => 25, 'none' => 35])) {
                'refunded' => ['refunded', $total],
                'partial' => ['partially_refunded', round($total * $this->rand(30, 70) / 100, 2)],
                default => ['succeeded', 0.0],
            },
            $r->status === 'completed' && $this->chance(1.5) => ['partially_refunded', round($total * $this->rand(30, 60) / 100, 2)],
            default => ['succeeded', 0.0],
        };

        yield $this->payment($r, ++$n, $status, $total, $refunded, $r->confirmed_at, $r->confirmed_at, null);
    }

    private function payment(object $r, int $n, string $status, float $total, float $refunded, ?string $paidAt, string $at, ?string $failure): array
    {
        $mock = 'mock_'.$this->uuid('mock-payment', "{$r->id}-{$n}");
        $online = $this->stable("payment-online-{$r->id}-{$n}", 25) ? ($this->stable("payment-tabby-{$r->id}-{$n}", 50) ? 'tabby' : 'tamara') : null;

        // Through Tabby or Tamara: the gateway's own reference (Tabby's payment id; Tamara's order id), what it last said, and the reason a failed payment failed.
        $reference = match ($online) {
            'tabby' => 'tabby_pay_'.$this->uuid('tabby-payment', "{$r->id}-{$n}"),
            'tamara' => $this->uuid('tamara-order', "{$r->id}-{$n}"),
            default => $mock,
        };
        $gatewayStatus = match ($online) {
            'tabby' => ['succeeded' => 'CLOSED', 'refunded' => 'CLOSED', 'partially_refunded' => 'CLOSED', 'failed' => 'REJECTED'][$status] ?? 'CREATED',
            'tamara' => ['succeeded' => 'fully_captured', 'refunded' => 'fully_refunded', 'partially_refunded' => 'partially_refunded', 'failed' => 'declined'][$status] ?? 'new',
            default => null,
        };

        return [
            'uuid' => $this->uuid('payment', "{$r->id}-{$n}"),
            // Unique by construction: the reservation number in base 36 plus the attempt number.
            'reference_id' => 'PAY-'.strtoupper(str_pad(base_convert((string) $r->id, 10, 36), 6, '0', STR_PAD_LEFT)).$n,
            'reservation_id' => $r->id,
            'gateway' => $online ?? 'mock',
            'payment_id' => $reference,
            'provider_reference' => $reference,
            'checkout_url' => $online && $status === 'pending' ? "https://checkout.{$online}.example/{$reference}" : null,
            'expires_at' => $online && $status === 'pending' ? Carbon::parse($at)->addMinutes(15)->toDateTimeString() : null,
            'gateway_status' => $gatewayStatus,
            'amount' => $total,
            'currency' => $r->currency,
            'status' => $status,
            'failure_reason' => $failure,
            'failure_code' => $online && $status === 'failed' ? 'declined' : null,
            'refunded_amount' => $refunded,
            'paid_at' => in_array($status, ['succeeded', 'refunded', 'partially_refunded'], true) ? $paidAt : null,
            'raw_payload' => json_encode($online ? ['gateway' => $online, 'status' => $gatewayStatus] : ['simulate' => $status === 'failed' ? 'sync_failure' : ($status === 'pending' ? 'async' : 'sync_success'), 'amount' => $total]),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
