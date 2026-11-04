<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Str;

/**
 * Categories. Volume at scale 1: 40 more, on top of the 12 the demo data has (a handful inactive).
 * Prerequisites: none.
 */
class BulkCategorySeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $names = array_slice($this->extraCategoryNames(), 0, $this->scaled(40));
        $stamp = $this->ts($this->now);

        $this->insert('categories', (function () use ($names, $stamp) {
            foreach ($names as $i => $name) {
                yield [
                    'uuid' => $this->uuid('category', $i),
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => "{$name} experiences from the Red Sea and Mediterranean coasts.",
                    'image' => null,
                    'status' => $i % 10 === 9 ? 'inactive' : 'active',
                    // 100 and up: how the feature-definition seeder finds them.
                    'sort_order' => 100 + $i,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        })());
    }
}
