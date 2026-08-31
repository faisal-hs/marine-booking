<?php

namespace App\Http\Requests\Web\Admin\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.create');
    }

    public function rules(): array
    {
        return [
            'user_type' => ['required', Rule::in(['admin', 'provider', 'customer'])],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required_if:user_type,admin', 'nullable', 'string', 'min:8'],
            'role' => ['required_if:user_type,admin', 'nullable', 'string', 'exists:roles,name'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'business_name' => ['required_if:user_type,provider', 'nullable', 'string', 'max:255'],
            'preferred_language' => ['nullable', 'string', 'in:en,ar'],
        ];
    }

    public function payload(): array
    {
        return $this->only([
            'user_type',
            'name',
            'email',
            'phone',
            'password',
            'role',
            'job_title',
            'business_name',
            'preferred_language',
        ]);
    }
}
