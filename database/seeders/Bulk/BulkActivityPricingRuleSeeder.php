<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * The prices. A per-person option has an adult price (and a child price at 50 to 60% of it, and sometimes a token
 * infant price); every other model has one price. A few per-person options also have a dearer adult price for one
 * particular departure.
 * Volume at scale 1: about 37,000, all in the platform currency (SAR by default).
 * Prerequisites: BulkActivityBookingOptionSeeder, BulkActivitySchedulePeriodSeeder.
 */
class BulkActivityPricingRuleSeeder extends BulkSeeder
{
    private const MULTIPLIER = ['per_trip' => [3.0, 4.5], 'per_group' => [3.0, 3.0], 'fixed' => [1.0, 1.0], 'per_unit' => [1.0, 1.0], 'per_hour' => [1.0, 1.0], 'package' => [3.5, 3.5]];

    protected function seed(): void
    {
        $this->needs('activity_booking_options', 'BulkActivityBookingOptionSeeder');

        $have = array_flip(DB::table('activity_pricing_rules')->distinct()->pluck('activity_booking_option_id')->all());
        $periods = [];
        foreach (DB::table('activity_schedule_periods')->orderBy('id')->get(['id', 'activity_id']) as $period) {
            $periods[$period->activity_id][] = $period->id;
        }

        $this->insert('activity_pricing_rules', (function () use ($have, $periods) {
            $last = 0;
            while (true) {
                $options = DB::table('activity_booking_options')
                    ->join('activities', 'activities.id', '=', 'activity_booking_options.activity_id')
                    ->where('activities.slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%')
                    ->where('activity_booking_options.id', '>', $last)
                    ->orderBy('activity_booking_options.id')->limit(2000)
                    ->get(['activity_booking_options.id', 'activity_booking_options.activity_id', 'activity_booking_options.booking_model', 'activities.created_at']);

                if ($options->isEmpty()) {
                    return;
                }

                foreach ($options as $option) {
                    if (isset($have[$option->id])) {
                        continue;
                    }

                    $base = 120 + ($option->activity_id * 37) % 2400;

                    if ($option->booking_model === 'per_person') {
                        yield $this->rule($option, 'adult', $base);
                        yield $this->rule($option, 'child', $base * $this->rand(50, 60) / 100);
                        if ($this->chance(30)) {
                            yield $this->rule($option, 'infant', $base * 0.1);
                        }
                        // A dearer price for one specific departure.
                        if ($this->chance(4) && ! empty($periods[$option->activity_id])) {
                            yield $this->rule($option, 'adult', $base * 1.25, $this->pick($periods[$option->activity_id]), 'special');
                        }
                    } else {
                        [$low, $high] = self::MULTIPLIER[$option->booking_model];
                        yield $this->rule($option, null, $base * ($low + ($high - $low) * $this->rand(0, 100) / 100));
                    }
                }

                $last = $options->last()->id;
            }
        })());
    }

    private function rule(object $option, ?string $type, float $price, ?int $period = null, string $variant = 'base'): array
    {
        return [
            'uuid' => $this->uuid('pricing-rule', "{$option->id}-".($type ?? 'flat')."-{$variant}"),
            'activity_booking_option_id' => $option->id,
            'participant_type' => $type,
            // Whole 5s: prices look like prices.
            'price' => max(5, round($price / 5) * 5),
            'min_quantity' => null,
            'max_quantity' => null,
            'schedule_period_id' => $period,
            'currency' => config('platform.currency'),
            'is_active' => 1,
            'created_at' => $option->created_at,
            'updated_at' => $option->created_at,
        ];
    }
}
