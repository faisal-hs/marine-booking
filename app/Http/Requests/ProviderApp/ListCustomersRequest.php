<?php

namespace App\Http\Requests\ProviderApp;

class ListCustomersRequest extends ProviderPaginationRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'tier' => ['sometimes', 'nullable', 'string', 'in:vip,regular,normal'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Only the male or the female customers.
            'sex' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::enum(\App\Enums\Sex::class)],
        ];
    }
}
