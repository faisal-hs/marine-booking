<?php

namespace App\Http\Requests\CustomerApp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }

    public function rules(): array
    {
        return [
            'topic' => ['required', 'string', Rule::in(config('customer_app.support_topics'))],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'booking_id' => ['nullable', 'integer', 'min:1'],
            // A real upload, checked by what it is (not what its name says); never a path the client chooses.
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ];
    }
}
