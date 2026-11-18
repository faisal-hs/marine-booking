<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\UploadVerificationDocumentRequest;
use App\Http\Requests\ProviderApp\UploadVerificationDocumentsRequest;
use App\Models\ProviderDocument;
use App\Services\ProviderDocumentService;
use App\Services\ProviderDocumentStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The provider's verification documents: what is asked for (the types an admin configured, mandatory or optional), where each one stands, and
 * uploading or replacing one. Uploading and an admin's approval are separate steps: an upload is `pending` until an admin decides.
 */
class VerificationController extends ProviderAppController
{
    public function __construct(private readonly ProviderDocumentStatuses $statuses, private readonly ProviderDocumentService $documents) {}

    public function index(Request $request)
    {
        $provider = $this->requireProvider($request);

        return $this->ok($this->statuses->all($provider)->map(fn (array $row) => $this->describe($row))->all());
    }

    public function upload(UploadVerificationDocumentRequest $request, string $key)
    {
        $provider = $this->requireProvider($request);

        $document = $this->documents->submitForType($provider, $key, $request->file('file'));

        return $this->created(['key' => $key, 'status' => 'pending', 'uploaded_at' => $document->created_at->utc()->format('Y-m-d\TH:i:s\Z')], 'Document uploaded and sent for review');
    }

    /**
     * The file itself, through the expiring link that GET /provider/verification hands out. Documents are private: this link is the only way a provider
     * reads their own. It carries no token on purpose; the SIGNATURE proves it was issued for that document, and it stops working after a few minutes.
     */
    /**
     * ALL the documents in one request (documents[key]=<file>), all or nothing. The answer says what was uploaded, which mandatory documents still have to be sent, and is the
     * whole list as GET /provider/verification gives it, so the app can redraw its screen from this one answer.
     */
    public function uploadMany(UploadVerificationDocumentsRequest $request)
    {
        $provider = $this->requireProvider($request);
        $uploaded = $this->documents->submitMany($provider, $request->documents());
        $rows = $this->statuses->all($provider);
        // What is still to be sent: a mandatory document nothing is on file for, or that was rejected, has expired, or that an admin asked to be updated.
        $remaining = $rows->filter(fn (array $row) => $row['type']->is_mandatory && in_array($row['status'], [ProviderDocumentStatuses::MISSING, ProviderDocumentStatuses::REJECTED,
            ProviderDocumentStatuses::EXPIRED, ProviderDocumentStatuses::UPDATE_REQUESTED], true))->pluck('type.key')->values()->all();

        return $this->created([
            'uploaded' => array_map(fn (ProviderDocument $document) => ['key' => $document->type, 'status' => 'pending', 'uploaded_at' => $document->created_at->utc()->format('Y-m-d\TH:i:s\Z')], $uploaded),
            'required_remaining' => $remaining,
            'all_required_uploaded' => $remaining === [],
            'documents' => $rows->map(fn (array $row) => $this->describe($row))->all(),
        ], count($uploaded) === 1 ? 'Document uploaded and sent for review.' : count($uploaded).' documents uploaded and sent for review.');
    }

    public function file(Request $request, string $document): StreamedResponse
    {
        $model = ProviderDocument::where('uuid', $document)->firstOrFail();

        return $this->documents->download($model);
    }

    /** @param  array{type: \App\Models\VerificationDocumentType, status: string, document: ?ProviderDocument, request: ?\App\Models\ProviderDocumentEvent}  $row */
    private function describe(array $row): array
    {
        ['type' => $type, 'status' => $status, 'document' => $document, 'request' => $update] = $row;

        return [
            'key' => $type->key,
            'title' => $type->title(),
            // Both languages, whatever the request's: the app can show either without asking again.
            'title_ar' => $type->title_ar,
            'title_en' => $type->title_en,
            'status' => $status,
            'is_done' => $status === ProviderDocumentStatuses::APPROVED,
            'file_url' => $document ? URL::temporarySignedRoute('provider.verification.file', now()->addMinutes((int) config('wallet.statement_link_minutes')), ['document' => $document->uuid]) : null,
            // Why it was rejected, or what the admin wants changed.
            'review_note' => match ($status) {
                ProviderDocumentStatuses::UPDATE_REQUESTED => $update?->note,
                ProviderDocumentStatuses::REJECTED => $document?->review_note,
                default => null,
            },
            'description' => $type->description(),
            'is_mandatory' => $type->is_mandatory,
            'uploaded_at' => $document?->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $document?->expires_at?->toDateString(),
            'update_requested_at' => $update?->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            // An approved document nobody asked to update stays as it is.
            'can_upload' => $status !== ProviderDocumentStatuses::APPROVED,
            // What a file must be to be accepted, so the app can check it before sending it.
            'accepted_formats' => array_values(config('providers.documents.mimes')),
            'max_size_kb' => (int) config('providers.documents.max_kb'),
        ];
    }

    private function requireProvider(Request $request)
    {
        return $this->provider($request) ?? throw ProviderApiException::notFound('You have no provider account yet.');
    }
}
