<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * POST /provider/apply: everything an application needs in one multipart request. A document is required unless the provider already has
 * one of that type on file (a resubmission after a rejection may keep it).
 */
class ApplyAsProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $rules = [
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'string', 'max:120'],
            'category_ids' => ['required', 'array', 'min:1', 'max:20'],
            // Only categories that exist and are active, each once.
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'city' => ['required', 'string', 'max:120'],
            'area' => ['required', 'string', 'max:120'],
            'marina_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
        ];

        $provider = $this->user()->provider;

        foreach (config('providers.documents.required_types') as $type) {
            $onFile = $provider?->documents()->where('type', $type)->exists() ?? false;

            $rules[$type] = [Rule::requiredIf(! $onFile), 'nullable', 'file', 'mimes:'.implode(',', config('providers.documents.mimes')), 'max:'.config('providers.documents.max_kb')];
        }

        return $rules;
    }

    /** What the application service takes (the spec's phone and email are the BUSINESS contact, kept apart from the sign-in phone). */
    public function details(): array
    {
        $data = $this->safe()->only(['business_name', 'business_type', 'category_ids', 'city', 'area', 'marina_name', 'bio', 'address']);

        return $data + ['contact_phone' => $this->input('phone'), 'contact_email' => $this->input('email')];
    }

    /** @return array<string, UploadedFile|null> */
    public function documents(): array
    {
        return collect(config('providers.documents.required_types'))->mapWithKeys(fn (string $type) => [$type => $this->file($type)])->all();
    }
}
