<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\User;
use App\Notifications\Providers\ProviderDocumentReviewedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an admin does with a provider's documents: approve one, reject one (with a reason), or ask for a new version (with a reason). Every decision
 * is written to the review history with who made it, and the provider is told.
 *
 * Only a PENDING document can be approved or rejected: uploading and approving are separate steps, so a decision is always about something the
 * provider sent in. To take an approval back, ask for an update.
 */
class ProviderDocumentReviewService
{
    public function __construct(
        private readonly NotificationDispatchService $dispatcher,
        private readonly VerificationDocumentTypes $types,
        private readonly ProviderDocumentStatuses $statuses,
    ) {}

    public function approve(ProviderDocument $document, User $admin, ?CarbonInterface $expiresOn = null): ProviderDocument
    {
        $document = $this->decide($document, $admin, ProviderDocument::APPROVED, null, $expiresOn);

        $this->tell($document, ProviderDocumentEvent::APPROVED, null);

        return $document;
    }

    public function reject(ProviderDocument $document, User $admin, string $reason): ProviderDocument
    {
        $document = $this->decide($document, $admin, ProviderDocument::REJECTED, $reason, null);

        $this->tell($document, ProviderDocumentEvent::REJECTED, $reason);

        return $document;
    }

    /**
     * Verifying the provider is the decision that settles the documents it was based on: the ones still waiting are approved with it. The provider is
     * told once, that they were verified, not once per document.
     */
    public function approveAllPending(Provider $provider, ?User $admin): int
    {
        $pending = $provider->documents()->where('status', ProviderDocument::PENDING)->get();

        foreach ($pending as $document) {
            $document->update(['status' => ProviderDocument::APPROVED, 'review_note' => null, 'reviewed_by_user_id' => $admin?->id, 'reviewed_at' => now()]);

            ProviderDocumentEvent::create([
                'provider_id' => $provider->id, 'document_type' => $document->type, 'provider_document_id' => $document->id,
                'action' => ProviderDocumentEvent::APPROVED, 'note' => 'Approved together with the provider.', 'actor_user_id' => $admin?->id, 'created_at' => now(),
            ]);
        }

        return $pending->count();
    }

    /** Asks the provider for a new version of a type, whether or not one is on file. Stays open until they upload one. */
    public function requestUpdate(Provider $provider, string $typeKey, User $admin, string $reason): ProviderDocumentEvent
    {
        $type = $this->types->find($typeKey);

        if (! $type) {
            throw ValidationException::withMessages(['document_type' => ['This is not a document type that can be asked for.']]);
        }

        if ($this->statuses->openRequest($provider, $typeKey)) {
            throw ValidationException::withMessages(['document_type' => ['An update of this document was already requested and is waiting for the provider.']]);
        }

        $current = $provider->documents()->where('type', $typeKey)->first();

        $event = ProviderDocumentEvent::create([
            'provider_id' => $provider->id, 'document_type' => $typeKey, 'provider_document_id' => $current?->id,
            'action' => ProviderDocumentEvent::UPDATE_REQUESTED, 'note' => $reason, 'actor_user_id' => $admin->id, 'created_at' => now(),
        ]);

        $this->dispatcher->dispatchOnce("provider.document.update_requested:{$event->id}", $provider->user, new ProviderDocumentReviewedNotification($provider, ProviderDocumentEvent::UPDATE_REQUESTED, $type->title_en, $reason));

        return $event;
    }

    private function decide(ProviderDocument $document, User $admin, string $to, ?string $note, ?CarbonInterface $expiresOn): ProviderDocument
    {
        return DB::transaction(function () use ($document, $admin, $to, $note, $expiresOn) {
            $document = ProviderDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->superseded_at !== null) {
                throw ValidationException::withMessages(['document' => ['A newer version of this document replaced this one: review the newer one.']]);
            }

            if ($document->status !== ProviderDocument::PENDING) {
                throw ValidationException::withMessages(['document' => ["This document is already {$document->status}. To change that, ask the provider for an update."]]);
            }

            $document->update(['status' => $to, 'review_note' => $note, 'reviewed_by_user_id' => $admin->id, 'reviewed_at' => now(), 'expires_at' => $to === ProviderDocument::APPROVED ? $expiresOn?->toDateString() : null]);

            ProviderDocumentEvent::create([
                'provider_id' => $document->provider_id, 'document_type' => $document->type, 'provider_document_id' => $document->id,
                'action' => $to, 'note' => $note, 'actor_user_id' => $admin->id, 'created_at' => now(),
            ]);

            return $document;
        });
    }

    private function tell(ProviderDocument $document, string $outcome, ?string $note): void
    {
        $provider = $document->provider;
        $title = $this->types->find($document->type)?->title_en ?? $document->type;

        $this->dispatcher->dispatchOnce("provider.document.{$outcome}:{$document->id}", $provider->user, new ProviderDocumentReviewedNotification($provider, $outcome, $title, $note));
    }
}
