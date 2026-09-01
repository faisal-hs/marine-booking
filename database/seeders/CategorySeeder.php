<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * NOTE: the categories table has no translation/localization columns
     * (single `name` string) — per the "do not invent database columns /
     * do not modify the category schema" constraint, this seeder does NOT
     * add Arabic names, since doing so would require a schema change that
     * was never requested as part of this task. English names only.
     */
    public function run(): void
    {
        $categories = [
            'Diving',
            'Snorkeling',
            'Fishing',
            'Boat Trips',
            'Yacht Trips',
            'Parasailing',
            'Jet Ski',
            'Kayaking',
            'Sea Safari',
            'Island Trips',
            'Dolphin Watching',
            'Sailing',
        ];

        foreach ($categories as $index => $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'status' => 'active', 'sort_order' => $index]
            );
        }

        $this->command->info('Categories seeded: ' . implode(', ', $categories));
    }
}
