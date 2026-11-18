<?php

namespace App\Http\Requests\Concerns;

use App\Services\VerificationDocumentTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The documents that go with an application, DYNAMIC: whatever documents an admin has switched on (the document types, see GET /provider/document-types) are the keys the
 * application takes, and each type's REQUIREMENT decides the rule:
 *
 *   required type    must be sent, unless a document of that type is already on file (an earlier attempt, or a rejected application being sent again)
 *   optional type    may be sent, and is kept if it is
 *
 * A document is sent under its key in either of two ways, and both mean the same: as a form field named after the key (`commercial_registration=<file>`, how applications have
 * always been sent) or inside `documents` (`documents[commercial_registration]=<file>`, how GET/PUT /provider/document-types speak). If it is sent both ways, the one inside
 * `documents` is used. A key inside `documents` that is not an active document type is refused, so a typo is told, not silently dropped. Each file is held to what any
 * document upload is held to (pdf, jpg, jpeg, png or webp, up to the size limit).
 *
 * For a FormRequest that is the application: it adds documentRules() to its rules, calls refuseUnknownDocuments() from after(), and answers documents() with applicationDocuments().
 */
trait HasApplicationDocuments
{
    /** @return array<string, array<int, mixed>> */
    protected function documentRules(): array
    {
        $provider = $this->user()->provider;
        $file = ['file', 'mimes:'.implode(',', config('providers.documents.mimes')), 'max:'.config('providers.documents.max_kb')];
        $rules = ['documents' => ['sometimes', 'array']];

        foreach (app(VerificationDocumentTypes::class)->active() as $type) {
            $key = $type->key;
            $onFile = $provider?->documents()->where('type', $key)->exists() ?? false;

            // A required document is needed unless it is on file or arrives inside `documents`; an optional one never is.
            $rules[$key] = $type->is_mandatory
                ? array_merge([Rule::requiredIf(! $onFile && ! $this->hasFile("documents.{$key}")), 'nullable'], $file)
                : array_merge(['nullable'], $file);
            $rules["documents.{$key}"] = array_merge(['nullable'], $file);
        }

        return $rules;
    }

    /** A key inside `documents` that is not an active document type is an error, named. */
    protected function refuseUnknownDocuments(Validator $validator): void
    {
        $documents = $this->all()['documents'] ?? null;

        if (! is_array($documents)) {
            return;
        }

        $known = app(VerificationDocumentTypes::class)->active()->pluck('key')->all();

        foreach (array_keys($documents) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $validator->errors()->add("documents.{$key}", 'This is not a document you can send with an application. GET /provider/document-types lists them.');
            }
        }
    }

    /** @return array<string, UploadedFile|null> every active document type's key and the file sent for it (null when none was) */
    protected function applicationDocuments(): array
    {
        return app(VerificationDocumentTypes::class)->active()->mapWithKeys(fn ($type) => [$type->key => $this->file("documents.{$type->key}") ?? $this->file($type->key)])->all();
    }
}
