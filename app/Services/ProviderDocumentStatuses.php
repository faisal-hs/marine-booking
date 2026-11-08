<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\VerificationDocumentType;
use Illuminate\Support\Collection;

/**
 * Where each of a provider's documents stands. The status of a document type is worked out from what is on file, never stored:
 *
 *   update_requested  an admin asked for a new version and the provider has not uploaded one yet (whatever else is on file)
 *   missing           nothing uploaded
 *   pending           uploaded, waiting for an admin
 *   approved          an admin approved it
 *   rejected          an admin rejected it (the note says why)
 *   expired           approved, but past the date the admin gave it
 *
 * `is_done` is true only for approved: it cannot tell pending from rejected, which is why there is a status.
 */
class ProviderDocumentStatuses
{
    public const MISSING = 'missing';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const UPDATE_REQUESTED = 'update_requested';

    public function __construct(private readonly VerificationDocumentTypes $types) {}

    /**
     * One row per type a provider can be asked for.
     *
     * @return Collection<int, array{type: VerificationDocumentType, status: string, document: ?ProviderDocument, request: ?ProviderDocumentEvent}>
     */
    public function all(Provider $provider): Collection
    {
        $documents = $provider->documents()->get()->unique('type')->keyBy('type');
        $requests = $this->openRequests($provider);

        return $this->types->active()->map(fn (VerificationDocumentType $type) => $this->row($type, $documents->get($type->key), $requests->get($type->key)))->values();
    }

    /** @return array{type: VerificationDocumentType, status: string, document: ?ProviderDocument, request: ?ProviderDocumentEvent} */
    public function of(Provider $provider, VerificationDocumentType $type): array
    {
        return $this->row($type, $provider->documents()->where('type', $type->key)->first(), $this->openRequests($provider)->get($type->key));
    }

    /** The open request for an update of this type, if there is one. */
    public function openRequest(Provider $provider, string $type): ?ProviderDocumentEvent
    {
        return $this->openRequests($provider)->get($type);
    }

    /** @return Collection<string, ProviderDocumentEvent> type => the request, for the types where the last word was an admin asking */
    private function openRequests(Provider $provider): Collection
    {
        return $provider->documentEvents()
            ->whereIn('action', [ProviderDocumentEvent::SUBMITTED, ProviderDocumentEvent::UPDATE_REQUESTED])->get()
            ->groupBy('document_type')->map->last()
            ->filter(fn (ProviderDocumentEvent $last) => $last->action === ProviderDocumentEvent::UPDATE_REQUESTED);
    }

    private function row(VerificationDocumentType $type, ?ProviderDocument $document, ?ProviderDocumentEvent $request): array
    {
        $status = match (true) {
            $request !== null => self::UPDATE_REQUESTED,
            $document === null => self::MISSING,
            $document->isExpired() => self::EXPIRED,
            default => $document->status,
        };

        return ['type' => $type, 'status' => $status, 'document' => $document, 'request' => $request];
    }
}
