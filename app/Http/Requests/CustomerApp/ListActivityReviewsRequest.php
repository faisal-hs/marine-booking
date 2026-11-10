<?php

namespace App\Http\Requests\CustomerApp;

class ListActivityReviewsRequest extends PageRequest
{
    public function rules(): array
    {
        return parent::rules() + ['rating' => ['sometimes', 'nullable', 'integer', 'between:1,5']];
    }
}
