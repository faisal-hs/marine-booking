<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The review history of the seeded documents, derived from the documents themselves so the two always agree: every document was uploaded; every
 * approved or rejected one was decided (by the admin recorded on it, with its note); and about one approved document in twenty-five was later the
 * subject of a request for a new version, which is still open. Providers that already have history are left alone.
 * Volume at scale 1: about two history lines per document, 4% of the approved ones with a request for an update on top.
 * Prerequisites: BulkProviderDocumentSeeder.
 */
class BulkProviderDocumentEventSeeder extends BulkSeeder
{
    private const UPDATE_REASONS = ['Please upload a clearer scan.', 'The document is about to expire: send the renewed one.', 'We need the full document, all pages.', 'The business name changed: send an updated document.'];

    protected function seed(): void
    {
        $this->needs('provider_documents', 'BulkProviderDocumentSeeder');

        $have = array_flip(DB::table('provider_document_events')->distinct()->pluck('provider_id')->all());
        $admins = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereIn('roles.name', ['super_admin', 'admin'])->pluck('model_has_roles.model_id')->all();

        DB::table('provider_documents')->orderBy('id')->chunkById(2000, function ($documents) use ($have, $admins) {
            $this->insert('provider_document_events', (function () use ($documents, $have, $admins) {
                foreach ($documents as $d) {
                    if (isset($have[$d->provider_id])) {
                        continue;
                    }

                    yield $this->event($d, 'submitted', null, null, $d->created_at);

                    if ($d->reviewed_at !== null) {
                        yield $this->event($d, $d->status, $d->review_note, $d->reviewed_by_user_id, $d->reviewed_at);
                    }

                    // Only a version in force can be asked to be updated.
                    if ($d->status === 'approved' && $d->superseded_at === null && $this->stable("document-update-request:{$d->id}", 4)) {
                        $asked = Carbon::parse($d->reviewed_at)->addDays($this->rand(2, 20));

                        if ($asked->lessThanOrEqualTo($this->now)) {
                            yield $this->event($d, 'update_requested', $this->pick(self::UPDATE_REASONS), $admins ? $this->pick($admins) : null, $this->ts($asked));
                        }
                    }
                }
            })());
        });
    }

    private function event(object $d, string $action, ?string $note, ?int $actor, string $at): array
    {
        return ['provider_id' => $d->provider_id, 'document_type' => $d->type, 'provider_document_id' => $d->id, 'action' => $action, 'note' => $note, 'actor_user_id' => $actor, 'created_at' => $at];
    }
}
