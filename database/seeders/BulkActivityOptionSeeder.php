<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Database\Seeders\Bulk\Concerns\IteratesBulkActivities;

/**
 * The optional extras a guest can add (a photo package, a lunch box ...). Half the activities have 1 to 3; 8% of
 * extras are compulsory. Volume at scale 1: about 15,000.
 * Prerequisites: BulkActivitySeeder.
 */
class BulkActivityOptionSeeder extends BulkSeeder
{
    use BulkPools;
    use IteratesBulkActivities;

    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%'));

        $pool = $this->addOnPool();

        $this->insert('activity_options', (function () use ($pool) {
            foreach ($this->activitiesWithout('activity_options') as $activity) {
                if (! $this->stable("add-ons-{$activity->id}", 50)) {
                    continue;
                }

                foreach ((array) array_rand($pool, $this->rand(1, 3)) as $order => $index) {
                    yield [
                        'uuid' => $this->uuid('add-on', "{$activity->id}-{$index}"),
                        'activity_id' => $activity->id,
                        'name' => $pool[$index],
                        'description' => null,
                        'extra_price' => $this->pick([50, 75, 100, 150, 200, 300]),
                        'is_required' => $this->chance(8) ? 1 : 0,
                        'max_quantity' => $this->pick([null, 2, 4, 10]),
                        'sort_order' => $order,
                        'created_at' => $activity->created_at,
                        'updated_at' => $activity->created_at,
                    ];
                }
            }
        })());
    }
}
