<?php

namespace App\Http\Requests\Provider\Override;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAvailabilityOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'is_unavailable' => ['sometimes', 'boolean'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function payload(): array
    {
        return $this->only(['date_from', 'date_to', 'is_unavailable', 'price', 'instructions', 'status']);
    }
}
