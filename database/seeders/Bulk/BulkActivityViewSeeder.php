<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * Page views for the provider analytics: every published seeded activity was looked at by ten to sixty visitors over the last sixty days, one view
 * per visitor per day (a visitor is a hash, as in the app), each arriving from one of the four sources. Nothing is dated in the future. Activities
 * that already have views are left alone, so running it again adds nothing.
 * Volume at scale 1: about thirty-five views per published activity.
 * Prerequisites: BulkActivitySeeder.
 */
class BulkActivityViewSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('status', 'published'));

        $have = array_flip(DB::table('activity_views')->distinct()->pluck('activity_id')->all());

        DB::table('activities')->where('status', 'published')->whereNull('deleted_at')->orderBy('id')->pluck('id')->chunk(500)->each(function ($ids) use ($have) {
            $this->insert('activity_views', (function () use ($ids, $have) {
                foreach ($ids as $activityId) {
                    if (isset($have[$activityId])) {
                        continue;
                    }

                    foreach (range(1, $this->rand(10, 60)) as $i) {
                        $at = $this->daysAgo(0, 59);

                        yield [
                            'activity_id' => $activityId, 'visitor_hash' => sha1("bulk-view-{$activityId}-{$i}"), 'viewed_on' => $at->toDateString(),
                            'source' => $this->weighted(['direct' => 45, 'category' => 30, 'recommendations' => 17, 'social' => 8]), 'created_at' => $this->ts($at),
                        ];
                    }
                }
            })());
        });
    }
}
