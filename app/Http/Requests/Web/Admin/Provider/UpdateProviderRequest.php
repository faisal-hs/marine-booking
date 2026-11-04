<?php

namespace App\Http\Requests\Web\Admin\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('providers.update');
    }

    public function rules(): array
    {
        $ownerId = $this->route('provider')->user_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($ownerId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ownerId)],
            'business_name' => ['required', 'string', 'max:255'],
            'business_license' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'commission_rate' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->all();
    }
}
