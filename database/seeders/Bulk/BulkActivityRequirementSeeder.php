<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Database\Seeders\Bulk\Concerns\IteratesBulkActivities;

/**
 * What a guest must bring or meet. Volume at scale 1: 2 to 4 per activity (70% mandatory), about 45,000.
 * Prerequisites: BulkActivitySeeder.
 */
class BulkActivityRequirementSeeder extends BulkSeeder
{
    use BulkPools;
    use IteratesBulkActivities;

    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%'));

        $pool = $this->requirementPool();

        $this->insert('activity_requirements', (function () use ($pool) {
            foreach ($this->activitiesWithout('activity_requirements') as $activity) {
                foreach ($this->sampleKeys($pool, $this->rand(2, 4)) as $order => $index) {
                    yield [
                        'uuid' => $this->uuid('requirement', "{$activity->id}-{$index}"),
                        'activity_id' => $activity->id,
                        'text' => $pool[$index],
                        'is_mandatory' => $this->chance(70) ? 1 : 0,
                        'sort_order' => $order,
                        'created_at' => $activity->created_at,
                        'updated_at' => $activity->created_at,
                    ];
                }
            }
        })());
    }
}
