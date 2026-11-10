<?php

namespace App\Services\CustomerApp;

use Carbon\Carbon;

/**
 * How much of what a customer paid comes back when THEY cancel, by how long before the trip they do it (config/customer_app.php):
 * more than full_refund_hours: everything; more than partial_refund_hours: partial_refund_percent of it; closer than that: nothing.
 * The activity page, the cancellation page, the FAQ and the cancel itself all read this one class, so they cannot disagree.
 * (A provider cancelling always refunds everything: that is the provider app's own rule.)
 */
class CancellationPolicy
{
    /** @return list<array{hours: int, percent: float}> the windows, longest first: "more than `hours` before the trip: `percent` back" */
    public function tiers(): array
    {
        $c = config('customer_app.cancellation');
        $tiers = [['hours' => (int) $c['full_refund_hours'], 'percent' => 100.0]];

        if ((float) $c['partial_refund_percent'] > 0 && (int) $c['partial_refund_hours'] < (int) $c['full_refund_hours']) {
            $tiers[] = ['hours' => (int) $c['partial_refund_hours'], 'percent' => (float) $c['partial_refund_percent']];
        }

        return $tiers;
    }

    /** The share (0 to 100) that comes back if the customer cancels at $now for a trip starting at $tripStart. */
    public function refundPercent(Carbon $tripStart, ?Carbon $now = null): float
    {
        $hoursBefore = ($now ?? now())->floatDiffInHours($tripStart, false);

        foreach ($this->tiers() as $tier) {
            if ($hoursBefore > $tier['hours']) {
                return $tier['percent'];
            }
        }

        return 0.0;
    }

    /** What comes back of $paid. */
    public function refundAmount(float $paid, Carbon $tripStart, ?Carbon $now = null): float
    {
        return round($paid * $this->refundPercent($tripStart, $now) / 100, 2);
    }

    /** The rules as the app shows them, in the language of the request. @return list<array{more_than_hours: int, refund_percent: float, text: string}> */
    public function rules(): array
    {
        $rules = [];
        $tiers = $this->tiers();

        foreach ($tiers as $i => $tier) {
            $rules[] = ['more_than_hours' => $tier['hours'], 'refund_percent' => $tier['percent'], 'text' => $tier['percent'] >= 100
                ? __('customer_app.cancellation.full', ['hours' => $tier['hours']])
                : __('customer_app.cancellation.partial', ['hours' => $tier['hours'], 'from' => $tiers[$i - 1]['hours'], 'percent' => rtrim(rtrim(number_format($tier['percent'], 2), '0'), '.')])];
        }

        $last = end($tiers);
        $rules[] = ['more_than_hours' => 0, 'refund_percent' => 0.0, 'text' => __('customer_app.cancellation.none', ['hours' => $last['hours']])];

        return $rules;
    }

    public function isFree(): bool
    {
        return $this->tiers()[0]['hours'] > 0;
    }
}
