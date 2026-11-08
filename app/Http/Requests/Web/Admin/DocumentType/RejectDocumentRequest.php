<?php

namespace App\Http\Requests\Web\Admin\DocumentType;

use Illuminate\Foundation\Http\FormRequest;

/** Why a document is rejected: the provider is told. */
class RejectDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['note' => ['required', 'string', 'min:3', 'max:500']];
    }
}
