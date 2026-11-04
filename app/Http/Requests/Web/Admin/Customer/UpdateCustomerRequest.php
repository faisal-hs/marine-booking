<?php

namespace App\Http\Requests\Web\Admin\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('customers.update');
    }

    public function rules(): array
    {
        $ownerId = $this->route('customer')->user_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($ownerId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ownerId)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'preferred_language' => ['nullable', Rule::in(['en', 'ar'])],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->all();
    }
}
