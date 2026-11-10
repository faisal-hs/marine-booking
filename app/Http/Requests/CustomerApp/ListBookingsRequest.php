<?php

namespace App\Http\Requests\CustomerApp;

use App\Services\CustomerApp\BookingPresenter;
use Illuminate\Validation\Rule;

class ListBookingsRequest extends PageRequest
{
    public function rules(): array
    {
        return parent::rules() + ['status' => ['sometimes', 'nullable', Rule::in(BookingPresenter::FILTERS)]];
    }
}
