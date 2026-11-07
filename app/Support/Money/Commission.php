<?php

namespace App\Support\Money;

/**
 * The platform's commission on one booking, worked out from the provider's own terms (the same for every activity of that provider):
 *
 *   percentage   value is a percent of the amount the provider earned on the booking
 *   fixed        value is an amount, kept once per booking, never more than the booking itself
 *
 * Amounts are PHP floats rounded to two decimals at every step, as the rest of the platform does it.
 */
final class Commission
{
    public const PERCENTAGE = 'percentage';

    public const FIXED = 'fixed';

    public const TYPES = [self::PERCENTAGE, self::FIXED];

    public static function calculate(string $type, float $value, float $base): float
    {
        if ($base <= 0 || $value <= 0) {
            return 0.0;
        }

        return $type === self::FIXED ? round(min($value, $base), 2) : round($base * $value / 100, 2);
    }
}
