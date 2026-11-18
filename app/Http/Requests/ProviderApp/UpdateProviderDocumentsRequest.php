<?php

namespace App\Http\Requests\ProviderApp;

/**
 * Updating the provider's documents, one or all, through PUT/PATCH/POST /provider/document-types: a form upload with `documents[<key>]=<file>`, held to exactly what
 * UploadVerificationDocumentsRequest holds it to (it is the same request, naming the other list endpoint in its messages).
 */
class UpdateProviderDocumentsRequest extends UploadVerificationDocumentsRequest
{
    protected function listEndpoint(): string
    {
        return 'GET /provider/document-types';
    }
}
