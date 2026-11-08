<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;

class UploadVerificationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // mimes: (not "file" alone) so an SVG or HTML file is rejected by content type.
            'file' => ['required', 'file', 'mimes:'.implode(',', config('providers.documents.mimes')), 'max:'.config('providers.documents.max_kb')],
        ];
    }
}
