<?php

namespace App\Http\Requests\Customer\Reservation;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Accepts id, uuid, OR slug for the activity, and id/uuid for
            // the booking option and schedule period — resolved by the
            // Controller, matching the id/uuid interchangeability already
            // established across every other public/provider endpoint.
            'activity' => ['required', 'string'],
            'booking_option' => ['required', 'string'],
            'schedule_period' => ['nullable', 'string'],

            'reservation_date' => ['required', 'date_format:Y-m-d'],

            'participants' => ['nullable', 'array'],
            'participants.*' => ['integer', 'min:0'],

            'quantity' => ['nullable', 'integer', 'min:1'],
            'duration' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
