<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'preferred_language' => ['sometimes', 'string', 'in:en,ar'],
        ];
    }

    public function payload(): array
    {
        return $this->only(['name', 'email', 'date_of_birth', 'preferred_language']);
    }
}
