<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The three required documents (commercial registration, safety certification, insurance certificate) of every
 * provider that has submitted a request, plus an extra supporting document for one in ten.
 *
 * Unlike the activity photos (placeholder paths, as in the demo data), these have a REAL small PDF on the private
 * disk, one file per document, because the admin screen offers them for download and deleting a document deletes
 * its file.
 *
 * Volume at scale 1: about 6,800 rows and as many files.
 * Prerequisites: BulkProviderRequestSeeder.
 */
class BulkProviderDocumentSeeder extends BulkSeeder
{
    private const TYPES = [
        'commercial_registration' => 'cr.pdf',
        'safety_certification' => 'safety.pdf',
        'insurance_certificate' => 'insurance.pdf',
    ];

    private const REASONS = ['The document is not legible.', 'The name does not match the business name.', 'Please upload every page of the document.', 'The document has expired.'];

    protected function seed(): void
    {
        $this->needs('provider_requests', 'BulkProviderRequestSeeder');

        $disk = Storage::disk(config('providers.documents.disk'));
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $have = array_flip(DB::table('provider_documents')->distinct()->pluck('provider_id')->all());
        $admins = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereIn('roles.name', ['super_admin', 'admin'])->pluck('model_has_roles.model_id')->all();

        $providers = DB::table('provider_requests')
            ->join('providers', 'providers.id', '=', 'provider_requests.provider_id')
            ->groupBy('providers.id', 'providers.uuid', 'providers.verification_status')
            ->orderBy('providers.id')
            ->get(['providers.id', 'providers.uuid', 'providers.verification_status', DB::raw('min(provider_requests.submitted_at) as first_submitted')]);

        $this->insert('provider_documents', (function () use ($providers, $have, $disk, $pdf, $admins) {
            foreach ($providers as $provider) {
                if (isset($have[$provider->id])) {
                    continue;
                }

                $submitted = Carbon::parse($provider->first_submitted);
                $documents = self::TYPES;
                if ($this->chance(10)) {
                    $documents['other'] = 'supporting.pdf';
                }

                foreach ($documents as $type => $name) {
                    // An admin verified the provider on these documents (approved); a waiting provider's wait (pending); a rejected one's were turned down.
                    $status = match ($provider->verification_status) {
                        'verified' => 'approved', 'rejected' => 'rejected', default => 'pending',
                    };

                    // About one verified provider in twelve first sent a commercial registration that was rejected and then replaced.
                    if ($type === 'commercial_registration' && $status === 'approved' && $this->stable("document-reuploaded:{$provider->id}", 8)) {
                        yield $this->row($provider, $type, $name.'.old', $disk, $pdf, $submitted->copy()->subDays(3), 'rejected', $admins, supersededAt: $submitted, suffix: 'old');
                    }

                    yield $this->row($provider, $type, $name, $disk, $pdf, $submitted, $status, $admins);
                }
            }
        })());
    }

    /** @param  list<int>  $admins */
    private function row(object $provider, string $type, string $name, $disk, string $pdf, Carbon $created, string $status, array $admins, ?Carbon $supersededAt = null, string $suffix = ''): array
    {
        $key = trim("{$provider->id}-{$type}-{$suffix}", '-');
        $uuid = $this->uuid('provider-document', $key);
        $path = "provider-documents/{$provider->uuid}/{$uuid}.pdf";
        $disk->put($path, $pdf);

        $decided = $status === 'pending' ? null : $created->copy()->addDays($this->rand(1, 3))->min($this->now);
        $expires = null;

        // Some approved documents carry a valid-until date, and some of those dates have already passed.
        if ($status === 'approved' && $this->stable("document-expiry:{$key}", 10)) {
            $expires = $this->chance(30) ? $this->now->copy()->subDays($this->rand(1, 90)) : $this->now->copy()->addDays($this->rand(30, 400));
        }

        return [
            'uuid' => $uuid, 'provider_id' => $provider->id, 'type' => $type, 'title' => null, 'original_name' => $name, 'path' => $path,
            'mime_type' => 'application/pdf', 'size_bytes' => strlen($pdf),
            'status' => $status,
            'review_note' => $status === 'rejected' ? $this->pick(self::REASONS) : null,
            'reviewed_by_user_id' => $decided && $admins ? $this->pick($admins) : null,
            'reviewed_at' => $decided ? $this->ts($decided) : null,
            'expires_at' => $expires?->toDateString(),
            'superseded_at' => $supersededAt ? $this->ts($supersededAt) : null,
            'created_at' => $this->ts($created), 'updated_at' => $this->ts($decided ?? $created),
        ];
    }
}
