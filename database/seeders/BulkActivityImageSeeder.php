<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\IteratesBulkActivities;

/**
 * Photos. Like the demo data, these are PLACEHOLDER paths (activities/bulk/<id>-<n>.jpg) with no file behind them:
 * 50,000 image files would add nothing a path does not. The first photo is the cover.
 * Volume at scale 1: 3 to 6 per activity, about 65,000. Bare drafts get none.
 * Prerequisites: BulkActivitySeeder.
 */
class BulkActivityImageSeeder extends BulkSeeder
{
    use IteratesBulkActivities;

    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%'));

        $this->insert('activity_images', (function () {
            foreach ($this->activitiesWithout('activity_images') as $activity) {
                if ($this->isBareDraft($activity)) {
                    continue;
                }

                for ($n = 0, $count = $this->rand(3, 6); $n < $count; $n++) {
                    yield [
                        'uuid' => $this->uuid('activity-image', "{$activity->id}-{$n}"),
                        'activity_id' => $activity->id,
                        'path' => "activities/bulk/{$activity->id}-{$n}.jpg",
                        'sort_order' => $n,
                        'is_cover' => $n === 0 ? 1 : 0,
                        'created_at' => $activity->created_at,
                        'updated_at' => $activity->created_at,
                    ];
                }
            }
        })());
    }
}
