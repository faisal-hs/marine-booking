<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;

/** The account withdrawals are paid into. The IBAN is kept as capital letters with no spaces. */
class UpdateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['iban', 'swift_code'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(preg_replace('/\s+/', '', $this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'account_holder_name' => ['required', 'string', 'min:2', 'max:120'],
            'bank_name' => ['required', 'string', 'min:2', 'max:120'],
            'iban' => ['required', 'string', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/'],
            'swift_code' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
        ];
    }
}
