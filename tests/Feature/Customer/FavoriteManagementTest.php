<?php

namespace Tests\Feature\Customer;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCustomerToken(): array
    {
        $user = User::factory()->customer()->create();
        \App\Models\Customer::create(['user_id' => $user->id]);
        $token = $user->createToken('test', ['customer'])->plainTextToken;

        return [$user, $token];
    }

    protected function makePublishedActivity(): Activity
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        return Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
    }

    public function test_customer_can_favorite_a_published_activity(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        $activity = $this->makePublishedActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/favorites', ['activity' => $activity->uuid]);

        $response->assertCreated()->assertJsonPath('data.activity.title', 'Test Activity');
        $this->assertDatabaseHas('favorites', ['activity_id' => $activity->id]);
    }

    public function test_cannot_favorite_a_non_published_activity(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $draftActivity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Draft Activity',
            'slug' => 'draft-' . uniqid(),
            'status' => 'draft',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/favorites', ['activity' => $draftActivity->uuid]);

        $response->assertStatus(404);
    }

    public function test_cannot_favorite_the_same_activity_twice(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        $activity = $this->makePublishedActivity();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/favorites', ['activity' => $activity->uuid]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/favorites', ['activity' => $activity->uuid]);

        $response->assertStatus(422);
        $this->assertEquals(1, \App\Models\Favorite::where('activity_id', $activity->id)->count());
    }

    public function test_activity_field_is_required(): void
    {
        [$user, $token] = $this->makeCustomerToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/customer/favorites', []);

        $response->assertStatus(422)->assertJsonValidationErrors('activity');
    }

    public function test_customer_can_list_own_favorites(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        $activity = $this->makePublishedActivity();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/favorites', ['activity' => $activity->uuid]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/customer/favorites');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_customer_can_remove_a_favorite(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        $activity = $this->makePublishedActivity();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/customer/favorites', ['activity' => $activity->uuid]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/customer/favorites/{$activity->uuid}");

        $response->assertOk();
        $this->assertDatabaseMissing('favorites', ['activity_id' => $activity->id]);
    }

    public function test_removing_a_non_favorited_activity_returns_404(): void
    {
        [$user, $token] = $this->makeCustomerToken();
        $activity = $this->makePublishedActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/customer/favorites/{$activity->uuid}");

        $response->assertStatus(404);
    }

    public function test_favorites_are_scoped_per_customer(): void
    {
        [$userA, $tokenA] = $this->makeCustomerToken();
        [$userB, $tokenB] = $this->makeCustomerToken();
        $activity = $this->makePublishedActivity();

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/customer/favorites', ['activity' => $activity->uuid]);

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)->getJson('/api/v1/customer/favorites');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unauthenticated_request_cannot_access_favorites(): void
    {
        $response = $this->getJson('/api/v1/customer/favorites');

        $response->assertStatus(401);
    }
}
