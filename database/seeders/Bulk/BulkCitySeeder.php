<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cities: one for each distinct city the seeded providers are in (as the migration makes them from real providers), so every provider's activities can
 * be placed in a city; about one in twelve is switched off. Cities that exist are left alone, so running it again adds nothing.
 * Volume at scale 1: one per distinct provider city (a few dozen).
 * Prerequisites: BulkProviderSeeder.
 */
class BulkCitySeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('providers', 'BulkProviderSeeder');

        $have = DB::table('cities')->pluck('id', 'slug')->all();
        $rows = [];

        foreach (DB::table('providers')->whereNotNull('city')->where('city', '!=', '')->distinct()->orderBy('city')->pluck('city') as $i => $name) {
            $name = trim($name);
            $slug = Str::slug($name) ?: 'city-'.$i;

            if (isset($have[$slug])) {
                continue;
            }

            $have[$slug] = true;
            $rows[] = ['uuid' => $this->uuid('city', $slug), 'name_en' => $name, 'name_ar' => $name, 'slug' => $slug, 'image' => null, 'is_active' => $this->chance(91.7) ? 1 : 0,
                'sort_order' => $i, 'created_at' => $this->ts($this->daysAgo(300, 420)), 'updated_at' => $this->ts($this->now)];
        }

        $this->insert('cities', $rows);
    }
}
