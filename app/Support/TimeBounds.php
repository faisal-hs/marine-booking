<?php

namespace App\Support;

use App\Enums\BookingModel;
use App\Models\ActivityBookingOption;

/**
 * How a booking by TIME is measured, in MINUTES, for a price option:
 *
 *   unit  the BLOCK it is made of, the step it grows by, and what the option's price is the price of
 *   min   the shortest it can be
 *   max   the longest it can be (null: no limit)
 *
 * An option the provider configured in minutes (`min_duration_minutes`) has its unit and its minimum both equal to that, and `max_duration_minutes` for its maximum: 30 minutes at 200
 * is a block of 30 minutes costing 200, so 60 minutes costs 400, 90 costs 600 and 120 costs 800. An option that has not been (`min_duration_minutes` null) is as it always was: blocks of
 * an hour (the price is an hourly price), at least `min_duration` hours (an hour when it says nothing) and at most `max_duration` hours. An option by the day counts days.
 *
 * A length is valid when it is a whole number of blocks between the minimum and the maximum: see problem().
 */
final class TimeBounds
{
    /** @return array{unit: int, min: int, max: ?int} */
    public static function minutes(ActivityBookingOption $option): array
    {
        return self::of($option->booking_model->value, $option->min_duration, $option->max_duration, $option->min_duration_minutes, $option->max_duration_minutes);
    }

    /**
     * The same from the values themselves.
     *
     * @return array{unit: int, min: int, max: ?int}
     */
    public static function of(string $bookingModel, ?int $minDuration, ?int $maxDuration, ?int $minMinutes = null, ?int $maxMinutes = null): array
    {
        if ($bookingModel === BookingModel::PerDay->value) {
            return ['unit' => 1440, 'min' => (int) ($minDuration ?? 1) * 1440, 'max' => $maxDuration !== null ? (int) $maxDuration * 1440 : null];
        }

        if ($minMinutes !== null) {
            $max = $maxMinutes ?? ($maxDuration !== null ? (int) $maxDuration * 60 : null);

            // The longest is the longest whole number of blocks that fits in what the provider said.
            return ['unit' => $minMinutes, 'min' => $minMinutes, 'max' => $max !== null ? max($minMinutes, intdiv($max, $minMinutes) * $minMinutes) : null];
        }

        return ['unit' => 60, 'min' => (int) ($minDuration ?? 1) * 60, 'max' => $maxDuration !== null ? (int) $maxDuration * 60 : null];
    }

    /** Why `$minutes` is not a valid length for the option, or null when it is. */
    public static function problem(ActivityBookingOption $option, int $minutes): ?string
    {
        $b = self::minutes($option);

        return match (true) {
            $minutes < $b['min'] => "The shortest booking for this option is {$b['min']} minutes.",
            $b['max'] !== null && $minutes > $b['max'] => "The longest booking for this option is {$b['max']} minutes.",
            $minutes % $b['unit'] !== 0 => "A booking for this option is a whole number of {$b['unit']}-minute blocks (for example {$b['unit']}, ".(2 * $b['unit']).' or '.(3 * $b['unit']).' minutes).',
            default => null,
        };
    }
}
