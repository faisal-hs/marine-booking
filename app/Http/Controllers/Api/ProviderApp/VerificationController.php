<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\UploadVerificationDocumentRequest;
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
        ];
    }

    private function requireProvider(Request $request)
    {
        return $this->provider($request) ?? throw ProviderApiException::notFound('You have no provider account yet.');
    }
}
