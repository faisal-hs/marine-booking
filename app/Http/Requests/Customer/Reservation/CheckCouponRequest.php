<?php

namespace App\Http\Requests\Customer\Reservation;

use Illuminate\Foundation\Http\FormRequest;

/** What a customer sends to see what a coupon would take off, before booking: the same description of the booking as a reservation, without a date. */
class CheckCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = (new StoreReservationRequest)->rules();
        unset($rules['reservation_date']);
        // Here the code is the whole point, so it is required (the booking request makes it optional).
        $rules['coupon_code'] = ['required', 'string', 'max:40'];

        return $rules;
    }
}
