<?php

namespace App\Support\Money;

/**
 * What a customer pays on top of the activity's price, in ONE place so the summary, the booking and the ledger cannot disagree.
 *
 *   service fee = subtotal x service_fee_percent          (the subtotal is the price BEFORE any coupon)
 *   VAT         = (subtotal - discount + service fee) x vat_percent
 *   total       = subtotal - discount + service fee + VAT
 *
 * Each amount is rounded to two decimals as it is worked out. The service fee and the VAT are not the provider's: a provider earns the price after the
 * coupon (see Reservation::providerAmount()).
 */
final class CheckoutTotals
{
    /** @return array{service_fee: float, vat: float, total: float} */
    public static function compute(float $subtotal, float $discount): array
    {
        $fee = round($subtotal * (float) config('customer_app.service_fee_percent') / 100, 2);
        $vat = round(max(0, $subtotal - $discount + $fee) * (float) config('customer_app.vat_percent') / 100, 2);

        return ['service_fee' => $fee, 'vat' => $vat, 'total' => round($subtotal - $discount + $fee + $vat, 2)];
    }
}
