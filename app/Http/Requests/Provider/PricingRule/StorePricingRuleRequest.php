<?php

namespace App\Http\Requests\Provider\PricingRule;

use App\Enums\ParticipantType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participant_type' => ['nullable', 'string', Rule::in(array_map(fn($c) => $c->value, ParticipantType::cases()))],
            'price' => ['required', 'numeric', 'min:0'],
            'min_quantity' => ['nullable', 'integer', 'min:1'],
            'max_quantity' => ['nullable', 'integer', 'gte:min_quantity'],
            // Existence checked here; SAME-ACTIVITY ownership is checked
            // in ActivityPricingRuleService, not here — a Request has no
            // clean access to "the booking option's own activity" without
            // duplicating a query the Service already needs to run anyway.
            'schedule_period_id' => ['nullable', 'integer', 'exists:activity_schedule_periods,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return $this->only(['participant_type', 'price', 'min_quantity', 'max_quantity', 'schedule_period_id', 'currency', 'is_active']);
    }
}
