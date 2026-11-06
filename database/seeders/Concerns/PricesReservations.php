<?php

namespace Database\Seeders\Concerns;

/**
 * Prices one booking of an option from that option's own pricing rules, the way the app does, and says how many
 * people it takes up. Shared by the seeders that create reservations, so they all price (and fill a day) identically.
 *
 * The host class must offer rand(), chance() and weighted() (every Bulk seeder does), and each option must carry:
 * booking_model, rules (participant type, or "flat", to price), min_quantity / max_quantity, min_duration / max_duration,
 * duration_unit.
 */
trait PricesReservations
{
    /** @return array{participants: ?string, quantity: ?int, duration: ?int, total: float, breakdown: string, size: int} */
    protected function price(object $option): array
    {
        $rules = $option->rules;
        $none = ['participants' => null, 'quantity' => null, 'duration' => null];

        switch ($option->booking_model) {
            case 'per_person':
                $counts = ['adult' => (int) $this->weighted(['1' => 20, '2' => 45, '3' => 20, '4' => 15])];
                if (isset($rules['child']) && $this->chance(35)) {
                    $counts['child'] = $this->rand(1, 2);
                }
                if (isset($rules['infant']) && $this->chance(15)) {
                    $counts['infant'] = 1;
                }

                $lines = [];
                foreach ($counts as $type => $quantity) {
                    $lines[] = ['participant_type' => $type, 'quantity' => $quantity, 'unit_price' => $rules[$type] ?? $rules['adult'], 'total' => round($quantity * ($rules[$type] ?? $rules['adult']), 2)];
                }

                return ['participants' => json_encode($counts), 'quantity' => null, 'duration' => null, 'total' => round(array_sum(array_column($lines, 'total')), 2), 'breakdown' => json_encode($lines), 'size' => array_sum($counts)];

            case 'per_unit':
                $quantity = $this->rand($option->min_quantity ?? 1, $option->max_quantity ?? 2);
                $unit = $rules['flat'];

                return ['quantity' => $quantity] + $none + ['total' => round($quantity * $unit, 2), 'breakdown' => json_encode([['type' => 'unit', 'quantity' => $quantity, 'unit_price' => $unit, 'total' => round($quantity * $unit, 2)]]), 'size' => $quantity];

            case 'per_hour':
            case 'per_day':
                $duration = $this->rand($option->min_duration ?? 1, $option->max_duration ?? 4);
                $unit = $rules['flat'];
                $label = $option->booking_model === 'per_day' ? 'day' : ($option->duration_unit ?? 'hour');

                return ['duration' => $duration] + $none + ['total' => round($duration * $unit, 2), 'breakdown' => json_encode([['type' => $label, 'quantity' => $duration, 'unit_price' => $unit, 'total' => round($duration * $unit, 2)]]), 'size' => 4];

            default: // per_trip, per_group, fixed, package: one flat price
                $flat = $rules['flat'];
                $label = ['per_trip' => 'trip', 'per_group' => 'group', 'fixed' => 'fixed', 'package' => 'package'][$option->booking_model] ?? 'fixed';

                return ['duration' => $option->booking_model === 'package' ? $option->min_duration : null] + $none + ['total' => $flat, 'breakdown' => json_encode([['type' => $label, 'quantity' => 1, 'unit_price' => $flat, 'total' => $flat]]), 'size' => 4];
        }
    }
}
