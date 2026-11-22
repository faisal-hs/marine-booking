<?php

namespace App\Http\Requests\Auth\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            // male or female, optional; saved on the account when it is created.
            'sex' => ['nullable', \Illuminate\Validation\Rule::enum(\App\Enums\Sex::class)],
        ];
    }

    public function payload(): array
    {
        return $this->only(['phone', 'owner_name', 'business_name', 'sex']);
    }
}
