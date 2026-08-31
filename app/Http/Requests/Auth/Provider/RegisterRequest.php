<?php

namespace App\Http\Requests\Auth\Provider;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'owner_name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * Sanitized payload the Service expects — controllers never touch $request directly.
     */
    public function payload(): array
    {
        return $this->only(['owner_name', 'business_name', 'email', 'phone', 'password']);
    }
}
