<?php

namespace App\Http\Requests\ProviderApp;

use App\Services\VerificationDocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * All the provider's documents in ONE request: a form upload with a file for each document they are sending, named by the document's key,
 *
 *     documents[commercial_registration]=<file>   documents[safety_certification]=<file>   documents[insurance]=<file> ...
 *
 * (GET /provider/verification lists the keys). Any of them, from one to all: a document not sent is left as it is. Each file is held to what a single upload is held to
 * (pdf, jpg, jpeg, png or webp, up to the size limit), and a key that is not one of the document types is refused: all or nothing, so one bad file stores none of them.
 */
class UploadVerificationDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $file = ['file', 'mimes:'.implode(',', config('providers.documents.mimes')), 'max:'.config('providers.documents.max_kb')];

        return [
            'documents' => ['required', 'array', 'min:1', 'max:'.max(1, app(VerificationDocumentTypes::class)->active()->count())],
            // mimes: (not "file" alone) so an SVG or HTML file is rejected by content type.
            'documents.*' => $file,
        ];
    }

    /** The endpoint that lists the keys, named in the messages (a subclass for another endpoint says its own). */
    protected function listEndpoint(): string
    {
        return 'GET /provider/verification';
    }

    public function messages(): array
    {
        return ['documents.required' => 'Send at least one document, as documents[key]=<file>. '.$this->listEndpoint().' lists the keys.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $documents = $this->all()['documents'] ?? null;

            if (! is_array($documents)) {
                return;
            }

            $known = app(VerificationDocumentTypes::class)->active()->pluck('key')->all();

            foreach (array_keys($documents) as $key) {
                if (! in_array((string) $key, $known, true)) {
                    $validator->errors()->add("documents.{$key}", 'This is not a document you can upload. '.$this->listEndpoint().' lists them.');
                }
            }
        }];
    }

    /** @return array<string, UploadedFile> document key => file, in the order sent */
    public function documents(): array
    {
        return $this->file('documents') ?? [];
    }
}
