<?php

namespace Tests\Feature\Seeders;

use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_at_least_10_categories(): void
    {
        $this->seed(CategorySeeder::class);

        $this->assertGreaterThanOrEqual(10, Category::count());
    }

    public function test_every_category_has_a_unique_slug_and_active_status(): void
    {
        $this->seed(CategorySeeder::class);

        $categories = Category::all();

        $this->assertCount($categories->count(), $categories->pluck('slug')->unique());

        foreach ($categories as $category) {
            $this->assertEquals('active', $category->status);
        }
    }

    public function test_running_seeder_twice_does_not_duplicate_categories(): void
    {
        $this->seed(CategorySeeder::class);
        $firstCount = Category::count();

        $this->seed(CategorySeeder::class);
        $secondCount = Category::count();

        $this->assertEquals($firstCount, $secondCount);
    }
}
