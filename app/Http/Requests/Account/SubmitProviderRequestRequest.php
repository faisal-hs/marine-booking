<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Concerns\HasApplicationDocuments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Everything a provider request needs, in one multipart request. A document is required unless the
 * person already has one of that type on file (a resubmission after a rejection may keep it).
 */
class SubmitProviderRequestRequest extends FormRequest
{
    use HasApplicationDocuments;

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

        // The documents are whatever the admin has switched on, each required or optional as its type says (see HasApplicationDocuments).
        return $rules + $this->documentRules();
    }

    public function after(): array
    {
        return [fn ($validator) => $this->refuseUnknownDocuments($validator)];
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->safe()->only(['business_name', 'city', 'area', 'bio', 'address']);
    }

    /** @return array<string, UploadedFile|null> */
    public function documents(): array
    {
        return $this->applicationDocuments();
    }
}
