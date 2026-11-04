<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Database\Seeders\Bulk\Concerns\IteratesBulkActivities;

/**
 * Date ranges that close an activity (80%) or give it a special price, in the past and the future. Just over half
 * the activities have 1 to 3; each lasts 1 to 14 days. Volume at scale 1: about 17,000.
 * Prerequisites: BulkActivitySeeder.
 */
class BulkActivityAvailabilityOverrideSeeder extends BulkSeeder
{
    use BulkPools;
    use IteratesBulkActivities;

    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%'));

        $this->insert('activity_availability_overrides', (function () {
            foreach ($this->activitiesWithout('activity_availability_overrides') as $activity) {
                if (! $this->stable("overrides-{$activity->id}", 55)) {
                    continue;
                }

                for ($n = 0, $count = $this->rand(1, 3); $n < $count; $n++) {
                    $from = $this->now->copy()->startOfDay()->addDays($this->chance(60) ? $this->rand(5, 120) : -$this->rand(10, 150));
                    $closed = $this->chance(80);

                    yield [
                        'uuid' => $this->uuid('override', "{$activity->id}-{$n}"),
                        'activity_id' => $activity->id,
                        'date_from' => $from->toDateString(),
                        'date_to' => $from->copy()->addDays($this->rand(0, 13))->toDateString(),
                        'is_unavailable' => $closed ? 1 : 0,
                        'price' => $closed ? null : $this->pick([150, 250, 400, 650, 900]),
                        'instructions' => $this->chance(30) ? $this->pick(['Closed for maintenance', 'Public holiday', 'Special rate for the season', 'Weather watch']) : null,
                        'status' => $this->chance(5) ? 'inactive' : 'active',
                        'created_at' => $activity->created_at,
                        'updated_at' => $activity->created_at,
                    ];
                }
            }
        })());
    }
}
