<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\UpdateProviderDocumentsRequest;
use App\Services\ProviderApp\ProviderDocumentTypesView;
use App\Services\ProviderDocumentService;
use Illuminate\Http\Request;

/**
 * The provider's document screen, in two calls. Open to a provider who is not approved yet too (the documents are part of getting approved), like the verification endpoints
 * these are a second view of (the same documents and the same rules, in a simpler vocabulary).
 *
 *   GET  /provider/document-types           every type of document: its key, REQUIRED or OPTIONAL, and where the provider's own stands:
 *                                           not_uploaded, pending, need_update or accepted. A provider who has NOT APPLIED yet has every type not_uploaded: it is how
 *                                           they learn the keys to send with POST /provider/apply
 *   PUT  /provider/document-types           UPDATE the provider's documents, one or all of them, in one request (documents[key]=<file>), all or nothing.
 *   PATCH, POST: the same (a POST is for a form upload on a PHP older than 8.4, which reads files from a POST only)
 */
class DocumentTypeController extends ProviderAppController
{
    public function __construct(private readonly ProviderDocumentTypesView $view, private readonly ProviderDocumentService $documents) {}

    public function index(Request $request)
    {
        $provider = $this->requireProvider($request);

        return $this->ok($this->view->rows($provider)->map(fn (array $row) => $this->view->describe($row))->all());
    }

    public function update(UpdateProviderDocumentsRequest $request)
    {
        $provider = $this->requireProvider($request);
        $updated = $this->documents->submitMany($provider, $request->documents(), 'accepted');
        $rows = $this->view->rows($provider);
        $remaining = $this->view->requiredRemaining($rows);

        return $this->ok([
            'updated' => array_map(fn ($document) => ['key' => $document->type, 'status' => ProviderDocumentTypesView::PENDING, 'updated_at' => $document->created_at->utc()->format('Y-m-d\TH:i:s\Z')], $updated),
            'required_remaining' => $remaining,
            'all_required_provided' => $remaining === [],
            'documents' => $rows->map(fn (array $row) => $this->view->describe($row))->all(),
        ], count($updated) === 1 ? 'Document updated and sent for review.' : count($updated).' documents updated and sent for review.');
    }

    private function requireProvider(Request $request)
    {
        return $this->provider($request) ?? throw ProviderApiException::notFound('You have no provider account yet.');
    }
}
