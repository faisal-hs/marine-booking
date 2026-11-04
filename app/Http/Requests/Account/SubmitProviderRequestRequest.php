<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything a provider request needs, in one multipart request. A document is required unless the
 * person already has one of that type on file (a resubmission after a rejection may keep it).
 */
class SubmitProviderRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $rules = [
            'business_name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'area' => ['required', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
        ];

        $provider = $this->user()->provider;

        foreach (config('providers.documents.required_types') as $type) {
            $onFile = $provider?->documents()->where('type', $type)->exists() ?? false;

            $rules[$type] = [
                Rule::requiredIf(! $onFile),
                'nullable',
                'file',
                'mimes:' . implode(',', config('providers.documents.mimes')),
                'max:' . config('providers.documents.max_kb'),
            ];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->safe()->only(['business_name', 'city', 'area', 'bio', 'address']);
    }

    /** @return array<string, \Illuminate\Http\UploadedFile|null> */
    public function documents(): array
    {
        return collect(config('providers.documents.required_types'))
            ->mapWithKeys(fn(string $type) => [$type => $this->file($type)])
            ->all();
    }
}
