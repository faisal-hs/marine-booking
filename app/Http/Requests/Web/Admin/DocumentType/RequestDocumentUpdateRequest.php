<?php

namespace App\Http\Requests\Web\Admin\DocumentType;

use Illuminate\Foundation\Http\FormRequest;

/** Asking a provider for a new version of a document, and why. */
class RequestDocumentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['document_type' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
