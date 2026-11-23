<?php

namespace App\Http\Requests\Provider\BookingOption;

use App\Enums\BookingModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

            // The option booked by TIME, in minutes (see StoreBookingOptionRequest): the block, and the longest. null takes either off.
            'min_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'max_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'min_duration' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_duration' => ['sometimes', 'nullable', 'integer', 'gte:min_duration'],
            'duration_unit' => ['sometimes', 'nullable', 'string', Rule::in(['hour', 'day'])],

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
            $min = $this->input('min_duration_minutes', $this->existing('min_duration_minutes'));
            $max = $this->input('max_duration_minutes', $this->existing('max_duration_minutes'));

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

    /** What the option being changed has now, for a field the request does not send. */
    private function existing(string $field): mixed
    {
        $option = $this->route('bookingOption');

        return $option instanceof Model ? $option->{$field} : null;
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
