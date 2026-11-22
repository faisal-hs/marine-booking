<?php

namespace App\Http\Requests\CustomerApp;

use App\Enums\ParticipantType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /customer-app/activities/{id}/coupons/validate: a coupon code (`coupon_code`, or `code`) checked for the activity of the URL. What it would take off is worked out when it is
 * told what is being booked, in either of two ways:
 *
 *   the booking itself   `date` with `time` or `slot_id`, and what the booking is made of (`booking_option_id`, `guests` / `participants` / `quantity` / `duration`):
 *                        the same fields as POST /customer-app/bookings/summary, and priced the same way
 *   an amount            `amount`: the price before the coupon, when the app already has it
 *
 * With neither, the coupon's own rules are checked (it exists, is on, has not expired, is for this activity, has not been used up) and there is no discount to give.
 */
class ValidateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // `code` is accepted for `coupon_code`, the name the older coupon check uses.
        if (! $this->filled('coupon_code') && $this->filled('code')) {
            $this->merge(['coupon_code' => $this->input('code')]);
        }
    }

    public function rules(): array
    {
        return [
            'coupon_code' => ['required', 'string', 'max:40'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:+1 year'],
            'time' => ['nullable', 'date_format:H:i'],
            'slot_id' => ['nullable', 'integer', 'min:1'],
            'booking_option_id' => ['nullable', 'integer', 'min:1'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:100'],
            'participants' => ['nullable', 'array'],
            'participants.*' => ['integer', 'min:0', 'max:100'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (($this->filled('time') || $this->filled('slot_id')) && ! $this->filled('date')) {
                $validator->errors()->add('date', 'Send the date of the departure (date) with its time or slot_id.');
            }

            if ($this->filled('date') && ! $this->filled('time') && ! $this->filled('slot_id')) {
                $validator->errors()->add('time', 'Send the time (time) or the id (slot_id) of the departure with its date.');
            }

            $kinds = array_map(fn (ParticipantType $t) => $t->value, ParticipantType::cases());

            foreach (array_keys((array) $this->input('participants', [])) as $kind) {
                if (! in_array((string) $kind, $kinds, true)) {
                    $validator->errors()->add("participants.{$kind}", "'{$kind}' is not a kind of guest: send ".implode(', ', $kinds).'.');
                }
            }
        }];
    }
}
