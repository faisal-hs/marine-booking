<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The notifications Tabby and Tamara sent about the bulk payments that went through them, so each such payment's story is told twice and agrees: a payment that succeeded was
 * approved and then captured (Tabby: AUTHORIZED, then CLOSED; Tamara: order_approved, then order_captured), one that failed was refused (REJECTED; order_declined), one still
 * waiting has none yet. Each is recorded once, as processed, a couple of seconds apart, as the webhook handler records them. Payments through the mock gateway have none:
 * it does not send notifications of this kind. Running it again adds nothing.
 * Volume at scale 1: about 55,000 (a quarter of the payments went through Tabby or Tamara, and a settled one has one or two notifications).
 * Prerequisites: BulkPaymentSeeder.
 */
class BulkPaymentEventSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('payments', 'BulkPaymentSeeder');

        $have = array_flip(DB::table('payment_events')->whereNotNull('payment_id')->distinct()->pluck('payment_id')->all());

        DB::table('payments')->whereIn('gateway', ['tabby', 'tamara'])->where('status', '!=', 'pending')->orderBy('id')
            ->select(['id', 'gateway', 'payment_id', 'status', 'paid_at', 'created_at'])
            ->chunk(2000, function ($payments) use ($have) {
                $this->insert('payment_events', (function () use ($payments, $have) {
                    foreach ($payments as $payment) {
                        if (isset($have[$payment->id])) {
                            continue;
                        }

                        $at = Carbon::parse($payment->paid_at ?? $payment->created_at);
                        $settled = $payment->status !== 'failed';
                        $steps = match ($payment->gateway) {
                            'tabby' => $settled ? ['AUTHORIZED', 'CLOSED'] : ['REJECTED'],
                            default => $settled ? ['order_approved', 'order_captured'] : ['order_declined'],
                        };

                        foreach ($steps as $i => $type) {
                            yield [
                                'gateway' => $payment->gateway,
                                'event_key' => "{$payment->payment_id}:{$type}",
                                'payment_id' => $payment->id,
                                'type' => $type,
                                'payload' => json_encode($payment->gateway === 'tabby' ? ['id' => $payment->payment_id, 'status' => $type] : ['order' => $payment->payment_id, 'event' => $type]),
                                'result' => $settled ? 'succeeded' : 'failed',
                                'created_at' => $this->ts($at->copy()->addSeconds($i * 2)),
                                'updated_at' => $this->ts($at->copy()->addSeconds($i * 2 + 1)),
                            ];
                        }
                    }
                })());
            });
    }
}
