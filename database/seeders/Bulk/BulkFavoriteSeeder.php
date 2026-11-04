<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * Saved activities: customers favouriting published activities, popular ones far more often. Each customer saves
 * an activity at most once.
 * Volume at scale 1: 120,000.
 * Prerequisites: BulkActivitySeeder, BulkCustomerSeeder.
 */
class BulkFavoriteSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('status', 'published'));
        $this->needs('customers', 'BulkCustomerSeeder');

        $activities = DB::table('activities')->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%')->where('status', 'published')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
        $customers = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->whereNull('users.deleted_at')->orderBy('customers.id')->pluck('customers.id')->all();
        $target = $this->scaled(120000);

        $this->insert('favorites', (function () use ($activities, $customers, $target) {
            $seen = [];
            // Bounded: a tiny catalogue cannot hold an unlimited number of distinct pairs.
            for ($attempt = 0, $made = 0; $made < $target && $attempt < $target * 3; $attempt++) {
                $customer = $customers[$this->rand(0, count($customers) - 1)];
                $activity = $activities[$this->skewed(count($activities))];

                if (isset($seen["{$customer}|{$activity}"])) {
                    continue;
                }
                $seen["{$customer}|{$activity}"] = true;
                $made++;

                $at = $this->ts($this->daysAgo(1, 500));
                yield ['customer_id' => $customer, 'activity_id' => $activity, 'created_at' => $at, 'updated_at' => $at];
            }
        })());
    }
}
