<?php

namespace App\Http\Requests\Provider\Override;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAvailabilityOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'is_unavailable' => ['sometimes', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function payload(): array
    {
        return $this->only(['date_from', 'date_to', 'is_unavailable', 'price', 'instructions', 'status']);
    }
}
