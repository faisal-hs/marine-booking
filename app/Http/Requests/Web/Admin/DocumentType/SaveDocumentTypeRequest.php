<?php

namespace App\Http\Requests\Web\Admin\DocumentType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Creating or editing a document type. Its key is chosen once and never changes, because documents and the API refer to it. */
class SaveDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_mandatory' => $this->boolean('is_mandatory'), 'is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        $type = $this->route('documentType');

        return [
            'key' => $type ? ['prohibited'] : ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,38}$/', Rule::unique('verification_document_types', 'key')],
            'title_en' => ['required', 'string', 'max:120'],
            'title_ar' => ['required', 'string', 'max:120'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string', 'max:500'],
            'is_mandatory' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
