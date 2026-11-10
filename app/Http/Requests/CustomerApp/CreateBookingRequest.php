<?php

namespace App\Http\Requests\CustomerApp;

class CreateBookingRequest extends BookingSummaryRequest
{
    /** How a customer can pay, as the app names it. */
    public const PAYMENT_METHODS = ['card', 'apple_pay', 'mada'];

    public function rules(): array
    {
        return parent::rules() + [
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'payment_method' => ['required', 'string', 'in:'.implode(',', self::PAYMENT_METHODS)],
            // The total is never sent: the server works it out.
            // Only the mock gateway reads this (as in the older payment endpoint), and the mock gateway is refused in production: it picks the simulated outcome.
            'simulate' => ['sometimes', 'string', 'in:sync_success,sync_failure,async'],
        ];
    }
}
