<?php

namespace App\Http\Requests\ProviderApp;

class ListReviewsRequest extends ProviderPaginationRequest
{
    public function rules(): array
    {
        return parent::rules() + ['activity_id' => ['sometimes', 'nullable', 'integer', 'min:1']];
    }
}
