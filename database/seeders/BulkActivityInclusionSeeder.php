<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Database\Seeders\Bulk\Concerns\IteratesBulkActivities;

/**
 * What is and is not included. Volume at scale 1: 2 to 4 inclusions and 1 to 2 exclusions per activity, about 67,000.
 * Prerequisites: BulkActivitySeeder.
 */
class BulkActivityInclusionSeeder extends BulkSeeder
{
    use BulkPools;
    use IteratesBulkActivities;

    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%'));

        $included = $this->inclusionPool();
        $excluded = $this->exclusionPool();

        $this->insert('activity_inclusions', (function () use ($included, $excluded) {
            foreach ($this->activitiesWithout('activity_inclusions') as $activity) {
                $order = 0;

                foreach ([['included', $included, $this->rand(2, 4)], ['excluded', $excluded, $this->rand(1, 2)]] as [$type, $pool, $count]) {
                    foreach ((array) array_rand($pool, $count) as $index) {
                        yield [
                            'uuid' => $this->uuid('inclusion', "{$activity->id}-{$type}-{$index}"),
                            'activity_id' => $activity->id,
                            'type' => $type,
                            'description' => $pool[$index],
                            'sort_order' => $order++,
                            'created_at' => $activity->created_at,
                            'updated_at' => $activity->created_at,
                        ];
                    }
                }
            }
        })());
    }
}
