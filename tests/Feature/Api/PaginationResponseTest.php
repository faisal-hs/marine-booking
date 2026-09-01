<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginationResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function makePublishedActivities(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->provider()->create();
            $provider = Provider::create(['user_id' => $user->id, 'business_name' => "Provider {$i}", 'slug' => 'provider-' . uniqid()]);
            $category = Category::create(['name' => "Category {$i}", 'slug' => 'category-' . uniqid(), 'status' => 'active']);
            Activity::create([
                'provider_id' => $provider->id,
                'category_id' => $category->id,
                'title' => "Activity {$i}",
                'slug' => 'activity-' . uniqid(),
                'status' => 'published',
            ]);
        }
    }

    public function test_public_activities_response_has_the_exact_pagination_shape(): void
    {
        $this->makePublishedActivities(3);

        $response = $this->getJson('/api/v1/public/activities?per_page=2&current_page=1');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'pagination' => ['current_page', 'total_pages', 'per_page', 'total_items'],
            ])
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total_items', 3)
            ->assertJsonPath('pagination.total_pages', 2)
            ->assertJsonCount(2, 'data');

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertArrayNotHasKey('links', $response->json());
    }

    public function test_current_page_query_parameter_actually_pages_results(): void
    {
        $this->makePublishedActivities(3);

        $page2 = $this->getJson('/api/v1/public/activities?per_page=2&current_page=2');

        $page2->assertOk()
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_per_page_defaults_to_15_when_not_specified(): void
    {
        $this->makePublishedActivities(3);

        $response = $this->getJson('/api/v1/public/activities');

        $response->assertOk()->assertJsonPath('pagination.per_page', 15);
    }

    public function test_per_page_above_max_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/public/activities?per_page=99999');

        $response->assertStatus(422);
    }

    public function test_provider_activities_index_also_uses_pagination_shape(): void
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        Activity::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'A', 'slug' => 'a-' . uniqid()]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/provider/activities?per_page=5');

        $response->assertOk()
            ->assertJsonStructure(['pagination' => ['current_page', 'total_pages', 'per_page', 'total_items']])
            ->assertJsonPath('pagination.per_page', 5);
    }
}
