<?php

namespace App\Http\Requests\ProviderApp;

class ListCouponsRequest extends ProviderPaginationRequest
{
    public function rules(): array
    {
        return parent::rules() + ['status' => ['sometimes', 'nullable', 'string', 'in:active,inactive,expired']];
    }
}
