<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:20']];
    }

    public function payload(): array
    {
        return $this->only(['phone']);
    }
}
