<?php

namespace App\Http\Requests\Auth\Customer;

use Illuminate\Foundation\Http\FormRequest;

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
            'name' => ['nullable', 'string', 'max:255'],
            // The city the customer is in, saved on their account when it is created (free text, as a provider's city is). Optional; ignored for a customer who already has an account.
            'city' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function payload(): array
    {
        return $this->only(['phone', 'name', 'city']);
    }
}
