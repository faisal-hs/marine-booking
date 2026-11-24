<?php

namespace App\Support\Money;

/** How an amount is written to a payment gateway: two decimals, three for the currencies that have fils. */
final class GatewayAmount
{
    private const THREE_DECIMALS = ['KWD', 'BHD', 'OMR'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::THREE_DECIMALS, true) ? 3 : 2;
    }

    /** "250.00" (a string, as Tabby wants it). */
    public static function format(float|string $amount, string $currency): string
    {
        return number_format((float) $amount, self::decimals($currency), '.', '');
    }

    /** Two amounts are the same to the smallest unit of the currency. */
    public static function same(float|string|null $a, float|string|null $b, string $currency): bool
    {
        return $a !== null && $b !== null && self::format($a, $currency) === self::format($b, $currency);
    }
}
