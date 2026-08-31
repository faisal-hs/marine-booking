<?php

namespace App\Http\Requests\Web\Admin\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.update');
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($userId)],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'role' => ['sometimes', 'nullable', 'string', 'exists:roles,name'],
            'business_name' => ['sometimes', 'string', 'max:255'],
            'verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'rejected'])],
            'preferred_language' => ['sometimes', 'string', 'in:en,ar'],
        ];
    }

    public function payload(): array
    {
        return $this->only([
            'name',
            'email',
            'phone',
            'status',
            'role',
            'business_name',
            'verification_status',
            'preferred_language',
        ]);
    }
}
