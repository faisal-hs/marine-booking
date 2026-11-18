<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\VerificationDocumentType;
use App\Services\ProviderDocumentStatuses;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * The provider's documents as the app's document screen wants them: every type an admin asks for, with whether it is REQUIRED or OPTIONAL, and where the provider's own
 * document of that type stands in four words:
 *
 *   not_uploaded   nothing on file                                                      (what the other endpoints call `missing`)
 *   pending        sent, waiting for an admin to decide                                 (`pending`)
 *   need_update    the provider has to send a new one: it was rejected, it has expired, or an admin asked for an update, and `need_update_reason` says which
 *   accepted       an admin approved it                                                 (`approved`)
 *
 * It is a view of what ProviderDocumentStatuses works out (nothing more is stored), so it can never disagree with GET /provider/verification: the same document is
 * `missing` there and `not_uploaded` here.
 */
class ProviderDocumentTypesView
{
    public const NOT_UPLOADED = 'not_uploaded';

    public const PENDING = 'pending';

    public const NEED_UPDATE = 'need_update';

    public const ACCEPTED = 'accepted';

    /** The statuses ProviderDocumentStatuses uses, in the four words. */
    private const SIMPLE = [
        ProviderDocumentStatuses::MISSING => self::NOT_UPLOADED,
        ProviderDocumentStatuses::PENDING => self::PENDING,
        ProviderDocumentStatuses::APPROVED => self::ACCEPTED,
        ProviderDocumentStatuses::REJECTED => self::NEED_UPDATE,
        ProviderDocumentStatuses::EXPIRED => self::NEED_UPDATE,
        ProviderDocumentStatuses::UPDATE_REQUESTED => self::NEED_UPDATE,
    ];

    public function __construct(private readonly ProviderDocumentStatuses $statuses) {}

    /** @return Collection<int, array{type: VerificationDocumentType, status: string, document: ?ProviderDocument, request: ?ProviderDocumentEvent}> */
    public function rows(Provider $provider): Collection
    {
        return $this->statuses->all($provider);
    }

    public static function simple(string $status): string
    {
        return self::SIMPLE[$status] ?? self::NEED_UPDATE;
    }

    /**
     * @param  array{type: VerificationDocumentType, status: string, document: ?ProviderDocument, request: ?ProviderDocumentEvent}  $row
     * @return array<string, mixed>
     */
    public function describe(array $row): array
    {
        ['type' => $type, 'status' => $status, 'document' => $document, 'request' => $update] = $row;
        $simple = self::simple($status);

        return [
            'key' => $type->key,
            'title' => $type->title(),
            'title_ar' => $type->title_ar,
            'title_en' => $type->title_en,
            'description' => $type->description(),
            'requirement' => $type->is_mandatory ? 'required' : 'optional',
            'status' => $simple,
            // Why a document needs updating: an admin rejected it, it has expired, or an admin asked for a new one.
            'need_update_reason' => $simple === self::NEED_UPDATE ? $status : null,
            'review_note' => match ($status) {
                ProviderDocumentStatuses::UPDATE_REQUESTED => $update?->note,
                ProviderDocumentStatuses::REJECTED => $document?->review_note,
                default => null,
            },
            'uploaded_at' => $document?->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $document?->expires_at?->toDateString(),
            // An accepted document nobody asked to update stays as it is.
            'can_update' => $simple !== self::ACCEPTED,
            // The file the provider sent, through a link that expires (documents are private).
            'file_url' => $document ? URL::temporarySignedRoute('provider.verification.file', now()->addMinutes((int) config('wallet.statement_link_minutes')), ['document' => $document->uuid]) : null,
            'accepted_formats' => array_values(config('providers.documents.mimes')),
            'max_size_kb' => (int) config('providers.documents.max_kb'),
        ];
    }

    /**
     * The REQUIRED documents the provider still has to send: nothing uploaded, or one that needs updating. (One that is waiting for an admin is not: it is sent.)
     *
     * @param  Collection<int, array{type: VerificationDocumentType, status: string}>  $rows
     * @return list<string>
     */
    public function requiredRemaining(Collection $rows): array
    {
        return $rows->filter(fn (array $row) => $row['type']->is_mandatory && in_array(self::simple($row['status']), [self::NOT_UPLOADED, self::NEED_UPDATE], true))
            ->pluck('type.key')->values()->all();
    }
}
