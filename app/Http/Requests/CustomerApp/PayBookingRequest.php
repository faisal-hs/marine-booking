<?php

namespace App\Http\Requests\CustomerApp;

use Illuminate\Foundation\Http\FormRequest;

/** Paying for a booking that is waiting: optionally with another method than the one it was made with. */
class PayBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['sometimes', 'string', 'in:'.implode(',', CreateBookingRequest::PAYMENT_METHODS)],
            // As when booking: only the mock gateway reads it (local use; the mock is refused in production): it picks the simulated outcome.
            'simulate' => ['sometimes', 'string', 'in:sync_success,sync_failure,async'],
        ];
    }
}
