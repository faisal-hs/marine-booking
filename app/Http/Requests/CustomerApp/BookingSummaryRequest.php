<?php

namespace App\Http\Requests\CustomerApp;

use App\Enums\ParticipantType;
use Illuminate\Foundation\Http\FormRequest;

class BookingSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activity_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:+1 year'],
            // The departure: its time, or its id as the availability lists it.
            'time' => ['required_without:slot_id', 'nullable', 'date_format:H:i'],
            'slot_id' => ['required_without:time', 'nullable', 'integer', 'min:1'],
            // The price option (see price_options on the activity); the activity's default one when it is not sent.
            'booking_option_id' => ['nullable', 'integer', 'min:1'],
            // What the booking is made of, by the option's reservation type: persons (`guests`, or `participants` by kind), units (`quantity`), time (`duration`).
            // Which of them an option needs is decided once the option is known (Checkout::details), and said by key.
            'guests' => ['nullable', 'integer', 'min:1', 'max:100'],
            'participants' => ['nullable', 'array'],
            'participants.*' => ['integer', 'min:0', 'max:100'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $kinds = array_map(fn (ParticipantType $t) => $t->value, ParticipantType::cases());

            foreach (array_keys((array) $this->input('participants', [])) as $kind) {
                if (! in_array((string) $kind, $kinds, true)) {
                    $validator->errors()->add("participants.{$kind}", "'{$kind}' is not a kind of guest: send ".implode(', ', $kinds).'.');
                }
            }
        }];
    }
}
