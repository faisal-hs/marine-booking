<?php

namespace App\Http\Requests\Provider\BookingOption;

use App\Enums\BookingModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Note: unlike Store, this does not re-derive required_if rules
     * against the EXISTING record's booking_model if the client doesn't
     * resend it — Laravel's required_if only sees the current request's
     * input, not the DB. A partial update that doesn't touch booking_model
     * or duration fields together is intentionally left unvalidated for
     * that specific cross-field rule, matching how UpdateActivityRequest
     * already handles the analogous partial-update tradeoff.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'booking_model' => ['sometimes', 'string', Rule::in(array_map(fn($c) => $c->value, BookingModel::cases()))],

            'min_participants' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_participants' => ['sometimes', 'nullable', 'integer', 'gte:min_participants'],

            'min_quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_quantity' => ['sometimes', 'nullable', 'integer', 'gte:min_quantity'],

            'min_duration' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_duration' => ['sometimes', 'nullable', 'integer', 'gte:min_duration'],
            'duration_unit' => ['sometimes', 'nullable', 'string', Rule::in(['hour', 'day'])],

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
