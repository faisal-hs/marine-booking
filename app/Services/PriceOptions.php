<?php

namespace App\Services;

use App\Enums\BookingModel;
use App\Enums\ParticipantType;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivityPricingRule;
use App\Support\ReservationType;
use App\Support\TimeBounds;

/**
 * The PRICE OPTIONS of an activity, as an app draws them: every booking option with its reservation type (what a booking of it is made of), the limits a booking has to keep,
 * and its prices. The same answer on the customer's activity page and the provider's, so the two screens agree.
 *
 * `prices` are the option's BASE prices: one for each kind of person for a per-person option (adult, child, infant), one price for the unit, the hour or the day, one price for the
 * whole booking for a fixed one. A provider can also price a particular departure differently: `has_departure_prices` says so, and the exact price of a booking is what
 * POST /customer-app/bookings/summary answers.
 */
class PriceOptions
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forActivity(Activity $activity, bool $activeOnly = true): array
    {
        $options = $activity->bookingOptions()->when($activeOnly, fn ($q) => $q->where('is_active', true))->with(['pricingRules' => fn ($q) => $q->orderBy('id')])->get();

        return $options->map(fn (ActivityBookingOption $option) => $this->describe($option, $activity))->values()->all();
    }

    /**
     * The booking options of an activity for the availability screen, flat: what a customer picks from and what it costs. For each active option:
     *   adult_price, child_price, infant_price                       a booking by persons (null for the others)
     *   price                                                        the one price of the others: a unit, a block of time, a day, the whole booking (null for persons)
     *   increment_minutes, min_minutes, max_minutes, min_minutes_price   a booking by time: the block it is made of (and the price is the price of ONE), the shortest and the longest it
     *                                                                can be, and what the SHORTEST costs (null for the others)
     * with everything `price_options` says (see describe()).
     *
     * @return list<array<string, mixed>>
     */
    public function forAvailability(Activity $activity): array
    {
        return array_map(function (array $o) {
            $prices = collect($o['prices'])->keyBy('type');
            $persons = $o['reservation_type'] === ReservationType::PERSONS;
            $one = $persons ? null : ($o['prices'][0]['price'] ?? null);
            $time = $o['reservation_type'] === ReservationType::TIME ? ($o['time_booking'] ?? TimeBounds::of($o['booking_model'], $o['min_duration'], $o['max_duration'])) : null;

            return [
                'id' => $o['id'], 'name' => $o['name'], 'reservation_type' => $o['reservation_type'], 'booking_model' => $o['booking_model'], 'price_unit' => $o['price_unit'],
                'is_default' => $o['is_default'], 'currency' => $o['prices'][0]['currency'] ?? config('platform.currency'),
                'adult_price' => $persons ? ($prices['adult']['price'] ?? null) : null,
                'child_price' => $persons ? ($prices['child']['price'] ?? null) : null,
                'infant_price' => $persons ? ($prices['infant']['price'] ?? null) : null,
                'price' => $one,
                'increment_minutes' => $time['unit'] ?? null,
                'min_minutes' => $time['min'] ?? null,
                'max_minutes' => $time['max'] ?? null,
                // The shortest booking is this many blocks of the price.
                'min_minutes_price' => $time && $one !== null ? round($one * $time['min'] / $time['unit'], 2) : null,
                'min_participants' => $o['min_participants'], 'max_participants' => $o['max_participants'],
                'min_quantity' => $o['min_quantity'], 'max_quantity' => $o['max_quantity'],
                'has_departure_prices' => $o['has_departure_prices'],
            ];
        }, $this->forActivity($activity));
    }

    /** @return array<string, mixed> */
    public function describe(ActivityBookingOption $option, ?Activity $activity = null): array
    {
        $model = $option->booking_model;
        $rules = $option->relationLoaded('pricingRules') ? $option->pricingRules : $option->pricingRules()->orderBy('id')->get();
        $active = $rules->where('is_active', true);
        $base = $active->whereNull('schedule_period_id');

        return [
            'id' => $option->id,
            'name' => $option->name,
            'description' => $option->description,
            'reservation_type' => ReservationType::of($model),
            'booking_model' => $model->value,
            'price_unit' => ReservationType::priceUnit($model, $option->min_duration_minutes),
            'is_default' => (bool) $option->is_default,
            'is_active' => (bool) $option->is_active,
            // The places a booking of this option takes: its own limit, else the activity's.
            'min_participants' => $option->min_participants,
            'max_participants' => $option->max_participants ?? $activity?->max_participants,
            'min_quantity' => $option->min_quantity,
            'max_quantity' => $option->max_quantity,
            'min_duration' => $option->min_duration,
            'max_duration' => $option->max_duration,
            // A booking by TIME is measured in minutes: the block (and the price is the price of one), the shortest and the longest. Null for the others.
            'min_duration_minutes' => $option->min_duration_minutes,
            'max_duration_minutes' => $option->max_duration_minutes,
            'time_booking' => $model === BookingModel::PerHour ? TimeBounds::minutes($option) : null,
            'duration_unit' => $option->duration_unit ?? match ($model) {
                BookingModel::PerHour => 'hour',
                BookingModel::PerDay => 'day',
                default => null,
            },
            'prices' => $this->prices($model, $base, $option),
            'has_departure_prices' => $active->whereNotNull('schedule_period_id')->isNotEmpty(),
        ];
    }

    /** @return list<array{type: string, label: string, price: float, currency: string}> */
    private function prices(BookingModel $model, $base, ?ActivityBookingOption $option = null): array
    {
        if ($model->usesParticipantPricing()) {
            $order = array_map(fn (ParticipantType $t) => $t->value, ParticipantType::cases());

            return $base->filter(fn (ActivityPricingRule $r) => $r->participant_type !== null)
                ->sortBy(fn (ActivityPricingRule $r) => array_search($r->participant_type->value, $order, true))
                ->map(fn (ActivityPricingRule $r) => $this->line($r->participant_type->value, $r))->values()->all();
        }

        // Everything else has one price, with no kind of person: for a unit, an hour, a day, or the whole booking.
        $rule = $base->first(fn (ActivityPricingRule $r) => $r->participant_type === null);

        if (! $rule) {
            return [];
        }

        $block = $option?->min_duration_minutes;
        $type = match (true) {
            $model->usesQuantity() => 'unit',
            $model === BookingModel::PerHour && $block !== null && $block !== 60 => "{$block}_minutes",
            $model === BookingModel::PerHour => 'hour',
            $model === BookingModel::PerDay => 'day',
            default => str_replace('per_', '', $model->value),   // trip, group; fixed and package as they are
        };

        return [$this->line($type, $rule)];
    }

    /** @return array{type: string, label: string, price: float, currency: string} */
    private function line(string $type, ActivityPricingRule $rule): array
    {
        return ['type' => $type, 'label' => ucfirst(str_replace('_', ' ', $type)), 'price' => (float) $rule->price, 'currency' => (string) $rule->currency];
    }
}
