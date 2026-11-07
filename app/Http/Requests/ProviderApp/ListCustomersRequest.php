<?php

namespace App\Http\Requests\ProviderApp;

class ListCustomersRequest extends ProviderPaginationRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'tier' => ['sometimes', 'nullable', 'string', 'in:vip,regular,normal'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
