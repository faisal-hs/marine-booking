<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;

/** page and per_page, as the provider app spec asks (the rest of the API uses current_page). per_page is capped at 100. */
class ProviderPaginationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.config('provider_app.per_page_max')],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?: config('provider_app.per_page_default'));
    }

    public function page(): int
    {
        return (int) ($this->input('page') ?: 1);
    }
}
