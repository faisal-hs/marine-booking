<?php

namespace App\Http\Requests\Provider\BookingOption;

use App\Enums\BookingModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // An option booked by TIME is configured in MINUTES: `min_duration_minutes` is the shortest booking and the block it grows by, and its price is the price of ONE block
            // (30 minutes at 200: 60 minutes is 400); `max_duration_minutes` is the longest. Without them it is in whole hours, as it always was: `min_duration` and `max_duration`.
            'min_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440', 'prohibited_unless:booking_model,per_hour'],
            'max_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10080', 'gte:min_duration_minutes', 'prohibited_unless:booking_model,per_hour'],
            'min_duration' => [Rule::requiredIf(fn () => in_array($this->input('booking_model'), ['per_day', 'package'], true) || ($this->input('booking_model') === 'per_hour' && ! $this->filled('min_duration_minutes'))), 'nullable', 'integer', 'min:1'],
            'max_duration' => [Rule::requiredIf(fn () => $this->input('booking_model') === 'per_day' || ($this->input('booking_model') === 'per_hour' && ! $this->filled('min_duration_minutes'))), 'nullable', 'integer', 'gte:min_duration'],
            'duration_unit' => ['nullable', 'string', Rule::in(['hour', 'day'])],

            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * The longest booking of a time option is a whole number of its blocks (`max_duration_minutes` is a multiple of `min_duration_minutes`); and a maximum needs a minimum, which is the block.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $min = $this->input('min_duration_minutes', null);
            $max = $this->input('max_duration_minutes', null);

            // Only numbers that are numbers: a value that already failed its own rule is not looked at again (and cannot be divided by).
            if (! is_numeric($max) || ($min !== null && ! is_numeric($min))) {
                return;
            }

            if ($min === null || (int) $min < 1) {
                $validator->errors()->add('min_duration_minutes', 'The min_duration_minutes field is required when max_duration_minutes is given: it is the block a booking is made of.');
            } elseif ((int) $max < (int) $min) {
                $validator->errors()->add('max_duration_minutes', 'The longest booking cannot be shorter than the shortest.');
            } elseif ((int) $max % (int) $min !== 0) {
                $validator->errors()->add('max_duration_minutes', "The longest booking must be a whole number of {$min}-minute blocks.");
            }
        });
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
            'min_duration_minutes',
            'max_duration_minutes',
            'is_active',
            'is_default',
            'sort_order',
        ]);
    }
}
