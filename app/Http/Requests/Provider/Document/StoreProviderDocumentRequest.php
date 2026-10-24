<?php

namespace App\Http\Requests\Provider\Document;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProviderDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(config('providers.documents.types'))],
            'title' => ['nullable', 'string', 'max:120'],
            // mimes: (not "file" alone) so an SVG or HTML file is rejected by content type.
            'file' => [
                'required',
                'file',
                'mimes:' . implode(',', config('providers.documents.mimes')),
                'max:' . config('providers.documents.max_kb'),
            ],
        ];
    }
}
