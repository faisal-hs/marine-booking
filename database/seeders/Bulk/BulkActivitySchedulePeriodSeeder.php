<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Database\Seeders\Bulk\Concerns\IteratesBulkActivities;

/**
 * The weekly departure times of each activity.
 * Volume at scale 1: 3 to 7 weekdays per activity, about 72,000. About 5% inactive and 2% soft-deleted; evening
 * slots that cross midnight are included. Bare drafts get none.
 * Prerequisites: BulkActivitySeeder.
 */
class BulkActivitySchedulePeriodSeeder extends BulkSeeder
{
    use BulkPools;
    use IteratesBulkActivities;

    private const SLOTS = [['07:00', '11:00'], ['08:00', '12:00'], ['09:00', '13:00'], ['10:00', '14:00'], ['13:00', '17:00'], ['16:00', '19:00'], ['18:30', '21:00'], ['21:00', '00:30']];

    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%'));

        $this->insert('activity_schedule_periods', (function () {
            foreach ($this->activitiesWithout('activity_schedule_periods') as $activity) {
                if ($this->isBareDraft($activity)) {
                    continue;
                }

                foreach ((array) array_rand(range(0, 6), $this->rand(3, 7)) as $order => $day) {
                    [$start, $end] = $this->pick(self::SLOTS);
                    // The first departure is always live: a published activity must have at least one.
                    $deleted = $order > 0 && $this->chance(2);

                    yield [
                        'uuid' => $this->uuid('schedule', "{$activity->id}-{$day}"),
                        'activity_id' => $activity->id,
                        'day_of_week' => $day,
                        'start_time' => $start,
                        'end_time' => $end,
                        'crosses_midnight' => $start > $end ? 1 : 0,
                        'instructions' => $this->chance(40) ? $this->pick($this->scheduleNotes()) : null,
                        'status' => $order > 0 && $this->chance(5) ? 'inactive' : 'active',
                        'sort_order' => $order,
                        'deleted_at' => $deleted ? $activity->created_at : null,
                        'created_at' => $activity->created_at,
                        'updated_at' => $activity->created_at,
                    ];
                }
            }
        })());
    }
}
