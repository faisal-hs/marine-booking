<?php

namespace App\Http\Requests\Admin\Reservation;

use App\Http\Requests\PaginationRequest;
use Illuminate\Validation\Rule;

class AdminReservationIndexRequest extends PaginationRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'string', Rule::in(['pending', 'confirmed', 'cancelled', 'expired', 'completed'])],
            'activity' => ['sometimes', 'string'],
            'booking_option' => ['sometimes', 'string'],
            'customer' => ['sometimes', 'string'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest'])],
        ]);
    }

    public function filters(): array
    {
        return $this->only([
            'status',
            'activity',
            'booking_option',
            'customer',
            'date',
            'date_from',
            'date_to',
            'search',
            'sort',
        ]);
    }
}
