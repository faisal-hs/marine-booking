<?php

namespace Database\Seeders\Bulk;

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

    protected function seed(): void
    {
        $this->needs('provider_requests', 'BulkProviderRequestSeeder');

        $disk = Storage::disk(config('providers.documents.disk'));
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $have = array_flip(DB::table('provider_documents')->distinct()->pluck('provider_id')->all());

        $providers = DB::table('provider_requests')
            ->join('providers', 'providers.id', '=', 'provider_requests.provider_id')
            ->groupBy('providers.id', 'providers.uuid')
            ->orderBy('providers.id')
            ->get(['providers.id', 'providers.uuid', DB::raw('min(provider_requests.submitted_at) as first_submitted')]);

        $this->insert('provider_documents', (function () use ($providers, $have, $disk, $pdf) {
            foreach ($providers as $provider) {
                if (isset($have[$provider->id])) {
                    continue;
                }

                $documents = self::TYPES;
                if ($this->chance(10)) {
                    $documents['other'] = 'supporting.pdf';
                }

                foreach ($documents as $type => $name) {
                    $uuid = $this->uuid('provider-document', "{$provider->id}-{$type}");
                    $path = "provider-documents/{$provider->uuid}/{$uuid}.pdf";
                    $disk->put($path, $pdf);

                    yield [
                        'uuid' => $uuid,
                        'provider_id' => $provider->id,
                        'type' => $type,
                        'title' => null,
                        'original_name' => $name,
                        'path' => $path,
                        'mime_type' => 'application/pdf',
                        'size_bytes' => strlen($pdf),
                        'created_at' => $provider->first_submitted,
                        'updated_at' => $provider->first_submitted,
                    ];
                }
            }
        })());
    }
}
