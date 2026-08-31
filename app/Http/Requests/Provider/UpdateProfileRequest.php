<?php

namespace App\Http\Requests\Provider;

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
        $providerId = $this->user()->provider?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'business_name' => ['sometimes', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($this->user()->id)],
        ];
    }

    public function payload(): array
    {
        return $this->only(['name', 'business_name', 'bio', 'address', 'lat', 'lng', 'email']);
    }
}
