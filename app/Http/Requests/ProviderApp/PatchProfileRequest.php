<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /provider/profile: only what changes. A field that is sent is a change, even to null where the field allows it. The business contact
 * is contact_phone / contact_email; email and name are the sign-in account's.
 */
class PatchProfileRequest extends FormRequest
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
            'business_name' => ['sometimes', 'string', 'max:255'],
            'business_type' => ['sometimes', 'string', 'max:120'],
            'marina_name' => ['sometimes', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/'],
            'contact_email' => ['sometimes', 'email:rfc', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:120'],
            'area' => ['sometimes', 'string', 'max:120'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'category_ids' => ['sometimes', 'array', 'min:1', 'max:20'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('deleted_at')],
        ];
    }

    /** What was sent (and validated), nothing else. */
    public function changes(): array
    {
        return $this->validated();
    }
}
