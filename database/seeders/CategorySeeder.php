<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = ['Diving', 'Snorkeling', 'Fishing', 'Boat Trips', 'Cruises', 'Water Sports'];

        foreach ($categories as $index => $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'status' => 'active', 'sort_order' => $index]
            );
        }

        $this->command->info('Categories seeded: ' . implode(', ', $categories));
    }
}
