<?php

namespace App\Http\Requests\Web\Admin\DocumentType;

use Illuminate\Foundation\Http\FormRequest;

/** Approving a document, optionally until a date (after which it shows as expired). */
class ApproveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['expires_at' => ['nullable', 'date', 'after:today']];
    }
}
