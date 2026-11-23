<?php

namespace App\Http\Requests\CustomerApp;

use App\Enums\ParticipantType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // The two kinds of booking. BY PERSONS (and units, or a number of hours, or one price): the `date`, the departure by its `time` or `slot_id`, and what it is made of.
            // BY TIME, a window from one time to another inside a departure: the `slot_id`, `from` and `to` ("2026-11-28 10:00", in the platform's timezone); the date is the one of `from`.
            'booking_type' => ['nullable', Rule::in(['persons', 'time_window'])],
            'date' => ['required_without:from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:+1 year'],
            'from' => ['nullable', 'date_format:Y-m-d H:i', 'required_with:to', 'before_or_equal:+1 year'],
            'to' => ['nullable', 'date_format:Y-m-d H:i', 'required_with:from'],
            // The departure: its time, or its id as the availability lists it.
            'time' => ['required_without:slot_id', 'nullable', 'date_format:H:i'],
            'slot_id' => ['required_without:time', 'required_with:from', 'nullable', 'integer', 'min:1'],
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

    protected function prepareForValidation(): void
    {
        // "2026-11-28T10:00" and "2026-11-28 10:00:00" are the same as "2026-11-28 10:00".
        $normalised = [];

        foreach (['from', 'to'] as $key) {
            if (is_string($this->input($key)) && preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', trim($this->input($key)), $m)) {
                $normalised[$key] = "{$m[1]} {$m[2]}";
            }
        }

        $this->merge($normalised);
    }

    public function after(): array
    {
        return [function ($validator) {
            // A booking_type that does not fit what was sent is said, by name.
            if ($this->input('booking_type') === 'time_window' && ! $this->filled('from')) {
                $validator->errors()->add('from', 'A booking by time needs from and to (and the slot_id).');
            }

            if ($this->input('booking_type') === 'persons' && $this->filled('from')) {
                $validator->errors()->add('booking_type', 'A booking by persons is for a date and its guests: from and to are for a booking by time.');
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
