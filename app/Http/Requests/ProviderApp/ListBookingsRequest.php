<?php

namespace App\Http\Requests\ProviderApp;

use App\Services\ProviderApp\ProviderBookings;

class ListBookingsRequest extends ProviderPaginationRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            // An empty value (?status=) means "no filter": apps send them.
            'filter' => ['sometimes', 'nullable', 'string', 'in:today,upcoming,pending,all'],
            'status' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', ProviderBookings::STATUSES)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
