<?php

namespace App\Http\Requests\CustomerApp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** A form upload sends "true" and "false" as words: they are turned into booleans before they are checked. */
    protected function prepareForValidation(): void
    {
        $n = $this->input('notifications');

        if (is_array($n)) {
            $this->merge(['notifications' => collect($n)->map(fn ($v) => is_string($v) ? (filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $v) : $v)->all()]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->user()->id)],
            // A real image upload, never a path the client names.
            'avatar' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'notifications' => ['sometimes', 'array'],
            'notifications.email' => ['sometimes', 'boolean'],
            'notifications.sms' => ['sometimes', 'boolean'],
            'notifications.marketing' => ['sometimes', 'boolean'],
        ];
    }
}
