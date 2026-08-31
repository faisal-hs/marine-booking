<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_only_active_categories_ordered(): void
    {
        Category::create(['name' => 'Fishing', 'slug' => 'fishing', 'status' => 'active', 'sort_order' => 2]);
        Category::create(['name' => 'Diving', 'slug' => 'diving', 'status' => 'active', 'sort_order' => 1]);
        Category::create(['name' => 'Hidden', 'slug' => 'hidden', 'status' => 'inactive', 'sort_order' => 0]);

        $response = $this->getJson('/api/v1/public/categories');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Diving')
            ->assertJsonPath('data.1.name', 'Fishing');
    }

    public function test_public_endpoint_requires_no_authentication(): void
    {
        $response = $this->getJson('/api/v1/public/categories');

        $response->assertOk();
    }
}
