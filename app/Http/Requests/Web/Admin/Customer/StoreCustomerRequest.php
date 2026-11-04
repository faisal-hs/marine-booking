<?php

namespace App\Http\Requests\Web\Admin\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('customers.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Unique across ALL users, deleted accounts included: a deleted account still holds its phone.
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'preferred_language' => ['nullable', Rule::in(['en', 'ar'])],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->all();
    }
}
