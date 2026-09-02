<?php

namespace App\Services;

use App\Models\ActivityBookingOption;
use App\Models\ActivityPricingRule;
use App\Repositories\Contracts\ActivityPricingRuleRepositoryInterface;
use Illuminate\Validation\ValidationException;

class ActivityPricingRuleService
{
    public function __construct(protected ActivityPricingRuleRepositoryInterface $repository) {}

    public function create(ActivityBookingOption $option, array $data): ActivityPricingRule
    {
        $this->assertSchedulePeriodBelongsToSameActivity($option, $data['schedule_period_id'] ?? null);
        $this->assertNoDuplicateActiveRule($option, $data);

        $data['activity_booking_option_id'] = $option->id;

        return $this->repository->create($data);
    }

    public function update(ActivityPricingRule $rule, array $data): ActivityPricingRule
    {
        $merged = array_merge([
            'participant_type' => $rule->participant_type?->value,
            'schedule_period_id' => $rule->schedule_period_id,
            'is_active' => $rule->is_active,
        ], $data);

        if (array_key_exists('schedule_period_id', $data)) {
            $this->assertSchedulePeriodBelongsToSameActivity($rule->bookingOption, $data['schedule_period_id']);
        }

        $this->assertNoDuplicateActiveRule($rule->bookingOption, $merged, ignoreId: $rule->id);

        return $this->repository->update($rule, $data);
    }

    public function delete(ActivityPricingRule $rule): void
    {
        $this->repository->delete($rule);
    }

    /**
     * "Pricing rules cannot reference another Activity" (spec §28). A
     * schedule_period_id is user-supplied — without this check, a
     * provider could attach a pricing rule to a schedule period belonging
     * to a DIFFERENT one of their own activities (or, if the period's own
     * ownership weren't checked upstream, potentially another provider's
     * activity). Mirrors the provider_id/activity_id spoofing defenses
     * used throughout this codebase.
     */
    protected function assertSchedulePeriodBelongsToSameActivity(ActivityBookingOption $option, ?int $schedulePeriodId): void
    {
        if ($schedulePeriodId === null) {
            return;
        }

        $belongsToSameActivity = $option->activity->schedulePeriods()->where('id', $schedulePeriodId)->exists();

        if (! $belongsToSameActivity) {
            throw ValidationException::withMessages([
                'schedule_period_id' => ['This schedule period does not belong to the same activity as the booking option.'],
            ]);
        }
    }

    /**
     * "Duplicate active rules for the same pricing dimensions must be
     * prevented" (spec §28). Two rules collide when they share the same
     * booking option + participant_type + schedule_period_id AND both
     * would be active — checked here rather than as a DB unique
     * constraint, since MySQL treats NULL as distinct from NULL in unique
     * indexes (see Step 1's migration comment), which would silently let
     * duplicate "general" rules (both dimensions null) through.
     */
    protected function assertNoDuplicateActiveRule(ActivityBookingOption $option, array $data, ?int $ignoreId = null): void
    {
        if (isset($data['is_active']) && ! $data['is_active']) {
            return; // an inactive rule can never collide — it will never be resolved anyway
        }

        $participantType = $data['participant_type'] ?? null;
        $schedulePeriodId = $data['schedule_period_id'] ?? null;

        $duplicate = $option->pricingRules()
            ->where('is_active', true)
            ->where(function ($query) use ($participantType) {
                $participantType === null
                    ? $query->whereNull('participant_type')
                    : $query->where('participant_type', $participantType);
            })
            ->where(function ($query) use ($schedulePeriodId) {
                $schedulePeriodId === null
                    ? $query->whereNull('schedule_period_id')
                    : $query->where('schedule_period_id', $schedulePeriodId);
            })
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'participant_type' => ['An active pricing rule already exists for this exact combination of participant type and schedule period.'],
            ]);
        }
    }
}
