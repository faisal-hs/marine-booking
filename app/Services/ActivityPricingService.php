<?php

namespace App\Services;

use App\Enums\BookingModel;
use App\Enums\ParticipantType;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivitySchedulePeriod;
use App\Repositories\Contracts\ActivityPricingRuleRepositoryInterface;
use App\Support\TimeBounds;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The single authoritative source of "how much does this cost". Kept
 * strictly separate from AvailabilityService ("is this available") per
 * the Module 08 architectural mandate — the two are never mixed, though
 * Module 09 (Reservations) will call both together.
 *
 * Every code path either returns a real, resolved price or throws a
 * ValidationException — there is no path that silently returns 0.
 */
class ActivityPricingService
{
    public function __construct(protected ActivityPricingRuleRepositoryInterface $pricingRuleRepository) {}

    /**
     * @param  array<string,int>|null  $participants  e.g. ['adult' => 2, 'child' => 1] — required for per_person
     * @param  int|null  $quantity  required for per_unit
     * @param  int|null  $duration  required for per_hour/per_day
     */
    public function quote(
        Activity $activity,
        ActivityBookingOption $bookingOption,
        ?ActivitySchedulePeriod $schedulePeriod = null,
        ?array $participants = null,
        ?int $quantity = null,
        ?int $duration = null,
        // A booking by the hour can be for a number of MINUTES (a window from one time to another): priced by blocks, see quotePerBlocks.
        ?int $minutes = null,
    ): array {
        if ($bookingOption->activity_id !== $activity->id) {
            throw ValidationException::withMessages([
                'booking_option' => ['This booking option does not belong to the given activity.'],
            ]);
        }

        if (! $bookingOption->is_active) {
            throw ValidationException::withMessages([
                'booking_option' => ['This booking option is not currently active.'],
            ]);
        }

        if ($schedulePeriod && $schedulePeriod->activity_id !== $activity->id) {
            throw ValidationException::withMessages([
                'schedule_period' => ['This schedule period does not belong to the given activity.'],
            ]);
        }

        $rules = $this->pricingRuleRepository->activeForBookingOption($bookingOption->id);

        return match ($bookingOption->booking_model) {
            BookingModel::PerPerson => $this->quotePerPerson($bookingOption, $rules, $schedulePeriod, $participants),
            BookingModel::PerTrip, BookingModel::PerGroup, BookingModel::Fixed, BookingModel::Package
            => $this->quoteFlat($bookingOption, $rules, $schedulePeriod, $participants),
            BookingModel::PerUnit => $this->quotePerUnit($bookingOption, $rules, $schedulePeriod, $quantity),
            BookingModel::PerHour, BookingModel::PerDay => $bookingOption->booking_model === BookingModel::PerHour && ($minutes !== null || $bookingOption->min_duration_minutes !== null)
                // By minutes: a window (`$minutes`), or any booking of an option the provider configured in minutes (an hour count is then that many hours of minutes).
                ? $this->quotePerBlocks($bookingOption, $rules, $schedulePeriod, $minutes ?? ($duration !== null ? $duration * 60 : null))
                : $this->quotePerDuration($bookingOption, $rules, $schedulePeriod, $duration),
        };
    }

    /**
     * Adult/child/infant pricing — the only model where price varies by
     * participant type. Every participant type with quantity > 0 must
     * resolve to a real active rule; a missing one is a 422, never a
     * silent $0 line item.
     */
    protected function quotePerPerson(ActivityBookingOption $option, Collection $rules, ?ActivitySchedulePeriod $period, ?array $participants): array
    {
        if (empty($participants)) {
            throw ValidationException::withMessages([
                'participants' => ['At least one participant is required for a per-person booking option.'],
            ]);
        }

        $this->assertParticipantCapacity($option, $participants);

        $breakdown = [];
        $subtotal = 0.0;
        $currency = null;

        foreach ($participants as $typeValue => $qty) {
            if ($qty <= 0) {
                continue;
            }

            try {
                $type = ParticipantType::from($typeValue);
            } catch (\ValueError) {
                throw ValidationException::withMessages([
                    "participants.{$typeValue}" => ["'{$typeValue}' is not a valid participant type."],
                ]);
            }

            $rule = $this->resolveRule($rules, $period?->id, $type);

            if (! $rule) {
                throw ValidationException::withMessages([
                    "participants.{$typeValue}" => ["No active price is configured for participant type '{$typeValue}' on this booking option."],
                ]);
            }

            $lineTotal = round((float) $rule->price * $qty, 2);
            $subtotal += $lineTotal;
            $currency ??= $rule->currency;

            $breakdown[] = [
                'participant_type' => $typeValue,
                'quantity' => $qty,
                'unit_price' => (float) $rule->price,
                'total' => $lineTotal,
            ];
        }

        if (empty($breakdown)) {
            throw ValidationException::withMessages([
                'participants' => ['At least one participant with a quantity greater than zero is required.'],
            ]);
        }

        return $this->buildResult($option, $currency, $subtotal, $breakdown);
    }

    /**
     * per_trip, per_group, fixed, and package all charge ONE configured
     * amount regardless of headcount — never multiplied by participants,
     * per the task's explicit "do not multiply the trip price" rule.
     * Capacity is still validated when a participant count is supplied.
     */
    protected function quoteFlat(ActivityBookingOption $option, Collection $rules, ?ActivitySchedulePeriod $period, ?array $participants): array
    {
        $this->assertParticipantCapacity($option, $participants);

        $rule = $this->resolveRule($rules, $period?->id, null);

        if (! $rule) {
            throw ValidationException::withMessages([
                'booking_option' => ['No active price is configured for this booking option.'],
            ]);
        }

        $price = round((float) $rule->price, 2);
        $label = str_replace('per_', '', $option->booking_model->value); // 'per_trip' -> 'trip', 'per_group' -> 'group'; 'fixed'/'package' unchanged

        return $this->buildResult($option, $rule->currency, $price, [
            ['type' => $label, 'quantity' => 1, 'unit_price' => $price, 'total' => $price],
        ]);
    }

    /**
     * per_unit multiplies price × quantity (e.g. 2 jet skis × 1000).
     */
    protected function quotePerUnit(ActivityBookingOption $option, Collection $rules, ?ActivitySchedulePeriod $period, ?int $quantity): array
    {
        if (! $quantity || $quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['A quantity of at least 1 is required for a per-unit booking option.'],
            ]);
        }

        $this->assertQuantityWithinBounds($option, $quantity);

        $rule = $this->resolveRule($rules, $period?->id, null);

        if (! $rule) {
            throw ValidationException::withMessages([
                'booking_option' => ['No active price is configured for this booking option.'],
            ]);
        }

        $unitPrice = (float) $rule->price;
        $total = round($unitPrice * $quantity, 2);

        return $this->buildResult($option, $rule->currency, $total, [
            ['type' => 'unit', 'quantity' => $quantity, 'unit_price' => $unitPrice, 'total' => $total],
        ]);
    }

    /**
     * per_hour/per_day multiply price × duration (e.g. 4 hours × 5000).
     */
    protected function quotePerDuration(ActivityBookingOption $option, Collection $rules, ?ActivitySchedulePeriod $period, ?int $duration): array
    {
        if (! $duration || $duration < 1) {
            throw ValidationException::withMessages([
                'duration' => ['A duration of at least 1 is required for this booking option.'],
            ]);
        }

        $this->assertDurationWithinBounds($option, $duration);

        $rule = $this->resolveRule($rules, $period?->id, null);

        if (! $rule) {
            throw ValidationException::withMessages([
                'booking_option' => ['No active price is configured for this booking option.'],
            ]);
        }

        $unitPrice = (float) $rule->price;
        $total = round($unitPrice * $duration, 2);
        $unitLabel = $option->booking_model === BookingModel::PerHour ? 'hour' : 'day';

        return $this->buildResult($option, $rule->currency, $total, [
            ['type' => $unitLabel, 'quantity' => $duration, 'unit_price' => $unitPrice, 'total' => $total],
        ]);
    }

    /**
     * Deterministic 4-tier resolution priority (spec-mandated, never
     * silently falls back past what's explicitly configured):
     *   1. booking option + schedule period + participant type
     *   2. booking option + schedule period (general, no participant type)
     *   3. booking option + participant type (no period)
     *   4. booking option only (fully general)
     */
    protected function resolveRule(Collection $rules, ?int $schedulePeriodId, ?ParticipantType $participantType)
    {
        if ($schedulePeriodId && $participantType) {
            $rule = $rules->first(fn($r) => $r->schedule_period_id === $schedulePeriodId && $r->participant_type === $participantType);
            if ($rule) {
                return $rule;
            }
        }

        if ($schedulePeriodId) {
            $rule = $rules->first(fn($r) => $r->schedule_period_id === $schedulePeriodId && $r->participant_type === null);
            if ($rule) {
                return $rule;
            }
        }

        if ($participantType) {
            $rule = $rules->first(fn($r) => $r->schedule_period_id === null && $r->participant_type === $participantType);
            if ($rule) {
                return $rule;
            }
        }

        return $rules->first(fn($r) => $r->schedule_period_id === null && $r->participant_type === null);
    }

    protected function assertParticipantCapacity(ActivityBookingOption $option, ?array $participants): void
    {
        if ($participants === null) {
            return;
        }

        $total = array_sum($participants);

        if ($option->min_participants !== null && $total < $option->min_participants) {
            throw ValidationException::withMessages([
                'participants' => ["This booking option requires at least {$option->min_participants} participants."],
            ]);
        }

        if ($option->max_participants !== null && $total > $option->max_participants) {
            throw ValidationException::withMessages([
                'participants' => ["This booking option allows at most {$option->max_participants} participants."],
            ]);
        }
    }

    protected function assertQuantityWithinBounds(ActivityBookingOption $option, int $quantity): void
    {
        if ($option->min_quantity !== null && $quantity < $option->min_quantity) {
            throw ValidationException::withMessages([
                'quantity' => ["Minimum quantity for this booking option is {$option->min_quantity}."],
            ]);
        }

        if ($option->max_quantity !== null && $quantity > $option->max_quantity) {
            throw ValidationException::withMessages([
                'quantity' => ["Maximum quantity for this booking option is {$option->max_quantity}."],
            ]);
        }
    }

    /**
     * A booking by TIME measured in minutes: the option's price is the price of ONE block (its `min_duration_minutes`: 30 minutes at 200, an hour when it has none), and the booking is a
     * whole number of blocks between the shortest and the longest, charged block by block: 30 minutes is 200, 60 is 400, 90 is 600, 120 is 800. A length that is not a whole number of
     * blocks, or is too short or too long, is refused (see TimeBounds::problem). The answer says the minutes, the block and how many blocks.
     */
    protected function quotePerBlocks(ActivityBookingOption $option, Collection $rules, ?ActivitySchedulePeriod $period, ?int $minutes): array
    {
        if (! $minutes || $minutes < 1) {
            throw ValidationException::withMessages(['duration' => ['A duration is required for this booking option.']]);
        }

        if ($problem = TimeBounds::problem($option, $minutes)) {
            throw ValidationException::withMessages(['duration' => [$problem]]);
        }

        $rule = $this->resolveRule($rules, $period?->id, null);

        if (! $rule) {
            throw ValidationException::withMessages(['booking_option' => ['No active price is configured for this booking option.']]);
        }

        $unit = TimeBounds::minutes($option)['unit'];
        $blocks = intdiv($minutes, $unit);
        $blockPrice = (float) $rule->price;
        $total = round($blockPrice * $blocks, 2);

        // A block of an hour is still "hour" (as it always was); any other block is a "duration_unit" of `unit_minutes`.
        return $this->buildResult($option, $rule->currency, $total, [
            ['type' => $unit === 60 ? 'hour' : 'duration_unit', 'quantity' => $blocks, 'unit_minutes' => $unit, 'minutes' => $minutes, 'unit_price' => $blockPrice, 'total' => $total],
        ]);
    }

    protected function assertDurationWithinBounds(ActivityBookingOption $option, int $duration): void
    {
        if ($option->min_duration !== null && $duration < $option->min_duration) {
            throw ValidationException::withMessages([
                'duration' => ["Minimum duration for this booking option is {$option->min_duration}."],
            ]);
        }

        if ($option->max_duration !== null && $duration > $option->max_duration) {
            throw ValidationException::withMessages([
                'duration' => ["Maximum duration for this booking option is {$option->max_duration}."],
            ]);
        }
    }

    protected function buildResult(ActivityBookingOption $option, ?string $currency, float $subtotal, array $breakdown): array
    {
        $subtotal = round($subtotal, 2);

        return [
            'booking_option_id' => $option->id,
            'booking_option_uuid' => $option->uuid,
            'pricing_model' => $option->booking_model->value,
            'currency' => $currency ?? config('platform.currency'),
            'subtotal' => $subtotal,
            'discount' => 0.0,
            'tax' => 0.0,
            'total' => $subtotal,
            'breakdown' => $breakdown,
        ];
    }
}
