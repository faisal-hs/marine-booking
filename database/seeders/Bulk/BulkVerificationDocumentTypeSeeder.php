<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * More kinds of document than the platform ships with, as an admin would add them from the dashboard: two optional ones and one switched off. The
 * eight that ship with the platform (three mandatory) come from a migration and are never touched here, and nothing mandatory is added, so no
 * seeded provider is suddenly "missing" a required document. Running it again adds nothing.
 * Volume at scale 1: 3 (it does not scale with anything).
 * Prerequisites: none.
 */
class BulkVerificationDocumentTypeSeeder extends BulkSeeder
{
    private const EXTRA = [
        ['diving_instructor_license', 'Diving instructor licence', 'رخصة مدرب غوص', true],
        ['boat_registration', 'Boat registration', 'تسجيل القارب', true],
        ['legacy_permit', 'Legacy permit', 'تصريح قديم', false],
    ];

    protected function seed(): void
    {
        $have = array_flip(DB::table('verification_document_types')->pluck('key')->all());
        $order = (int) DB::table('verification_document_types')->max('sort_order');

        $this->insert('verification_document_types', (function () use ($have, $order) {
            foreach (self::EXTRA as $i => [$key, $en, $ar, $active]) {
                if (isset($have[$key])) {
                    continue;
                }

                yield [
                    'uuid' => $this->uuid('document-type', $key), 'key' => $key, 'title_en' => $en, 'title_ar' => $ar, 'description_en' => null, 'description_ar' => null,
                    'is_mandatory' => false, 'is_active' => $active, 'sort_order' => $order + $i + 1, 'created_at' => $this->ts($this->now), 'updated_at' => $this->ts($this->now),
                ];
            }
        })());
    }
}
