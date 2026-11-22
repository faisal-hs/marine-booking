<?php

namespace App\Services;

use App\Enums\BookingModel;
use App\Enums\ParticipantType;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivityPricingRule;
use App\Support\ReservationType;

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
            'price_unit' => ReservationType::priceUnit($model),
            'is_default' => (bool) $option->is_default,
            'is_active' => (bool) $option->is_active,
            // The places a booking of this option takes: its own limit, else the activity's.
            'min_participants' => $option->min_participants,
            'max_participants' => $option->max_participants ?? $activity?->max_participants,
            'min_quantity' => $option->min_quantity,
            'max_quantity' => $option->max_quantity,
            'min_duration' => $option->min_duration,
            'max_duration' => $option->max_duration,
            'duration_unit' => $option->duration_unit ?? match ($model) {
                BookingModel::PerHour => 'hour',
                BookingModel::PerDay => 'day',
                default => null,
            },
            'prices' => $this->prices($model, $base),
            'has_departure_prices' => $active->whereNotNull('schedule_period_id')->isNotEmpty(),
        ];
    }

    /** @return list<array{type: string, label: string, price: float, currency: string}> */
    private function prices(BookingModel $model, $base): array
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

        $type = match (true) {
            $model->usesQuantity() => 'unit',
            $model === BookingModel::PerHour => 'hour',
            $model === BookingModel::PerDay => 'day',
            default => str_replace('per_', '', $model->value),   // trip, group; fixed and package as they are
        };

        return [$this->line($type, $rule)];
    }

    /** @return array{type: string, label: string, price: float, currency: string} */
    private function line(string $type, ActivityPricingRule $rule): array
    {
        return ['type' => $type, 'label' => ucfirst($type), 'price' => (float) $rule->price, 'currency' => (string) $rule->currency];
    }
}
