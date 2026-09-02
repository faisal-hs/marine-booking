<?php

namespace App\Http\Requests\Provider\PricingRule;

use App\Enums\ParticipantType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participant_type' => ['sometimes', 'nullable', 'string', Rule::in(array_map(fn($c) => $c->value, ParticipantType::cases()))],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'min_quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_quantity' => ['sometimes', 'nullable', 'integer', 'gte:min_quantity'],
            'schedule_period_id' => ['sometimes', 'nullable', 'integer', 'exists:activity_schedule_periods,id'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return $this->only(['participant_type', 'price', 'min_quantity', 'max_quantity', 'schedule_period_id', 'currency', 'is_active']);
    }
}
