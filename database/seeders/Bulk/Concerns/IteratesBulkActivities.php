<?php

namespace Database\Seeders\Bulk\Concerns;

use Illuminate\Support\Facades\DB;

/** Lets a child-table seeder walk the bulk activities that do not have rows in its table yet. */
trait IteratesBulkActivities
{
    /**
     * @param  list<string>  $extra  further activity columns the seeder needs
     * @return \Generator<int, object>
     */
    protected function activitiesWithout(string $childTable, array $extra = []): \Generator
    {
        // Which activities already have rows: read once, so a re-run only fills what is missing.
        $have = array_flip(DB::table($childTable)->distinct()->pluck('activity_id')->all());
        $columns = array_merge(['id', 'status', 'category_id', 'created_at'], $extra);
        $last = 0;

        while (true) {
            $rows = DB::table('activities')
                ->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%')
                ->where('id', '>', $last)
                ->orderBy('id')->limit(2000)->get($columns);

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $row) {
                if (! isset($have[$row->id])) {
                    yield $row;
                }
            }

            $last = $rows->last()->id;
        }
    }

    /**
     * A third of draft activities are still bare: no schedule, options, prices or photos yet, as a provider's
     * half-finished listing would be.
     */
    protected function isBareDraft(object $activity): bool
    {
        return $activity->status === 'draft' && $activity->id % 3 === 0;
    }
}
