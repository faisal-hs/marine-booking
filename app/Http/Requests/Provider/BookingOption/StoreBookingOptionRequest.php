<?php

namespace App\Http\Requests\Provider\BookingOption;

use App\Enums\BookingModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'booking_model' => ['required', 'string', Rule::in(array_map(fn($c) => $c->value, BookingModel::cases()))],

            'min_participants' => ['nullable', 'integer', 'min:1'],
            'max_participants' => ['nullable', 'integer', 'gte:min_participants'],

            'min_quantity' => ['nullable', 'integer', 'min:1'],
            'max_quantity' => ['nullable', 'integer', 'gte:min_quantity'],

            // per_hour/per_day genuinely need a duration RANGE; package
            // needs at least a single configured duration (spec's "4 Hour
            // Yacht Package" example) but not necessarily a max.
            'min_duration' => ['required_if:booking_model,per_hour,per_day,package', 'nullable', 'integer', 'min:1'],
            'max_duration' => ['required_if:booking_model,per_hour,per_day', 'nullable', 'integer', 'gte:min_duration'],
            'duration_unit' => ['nullable', 'string', Rule::in(['hour', 'day'])],

            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function payload(): array
    {
        return $this->only([
            'name',
            'description',
            'booking_model',
            'min_participants',
            'max_participants',
            'min_quantity',
            'max_quantity',
            'min_duration',
            'max_duration',
            'duration_unit',
            'is_active',
            'is_default',
            'sort_order',
        ]);
    }
}
