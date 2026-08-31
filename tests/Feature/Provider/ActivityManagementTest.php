<?php

namespace Tests\Feature\Provider;

use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedProvider(): array
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Test Diving',
            'slug' => 'test-diving-' . uniqid(),
        ]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;

        return [$user, $provider, $token];
    }

    public function test_provider_can_create_activity(): void
    {
        [$user, $provider, $token] = $this->authenticatedProvider();
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving', 'status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities', [
                'category_id' => $category->id,
                'title' => 'Red Sea Night Dive',
                'max_participants' => 10,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'red-sea-night-dive')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('activities', ['provider_id' => $provider->id, 'title' => 'Red Sea Night Dive']);
    }

    public function test_provider_id_cannot_be_spoofed_via_request_payload(): void
    {
        [$user, $provider, $token] = $this->authenticatedProvider();
        $otherUser = User::factory()->provider()->create();
        $otherProvider = Provider::create(['user_id' => $otherUser->id, 'business_name' => 'Other Diving', 'slug' => 'other-diving']);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving', 'status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities', [
                'provider_id' => $otherProvider->id, // attempted spoof — must be ignored
                'category_id' => $category->id,
                'title' => 'Spoofed Activity',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('activities', ['title' => 'Spoofed Activity', 'provider_id' => $provider->id]);
        $this->assertDatabaseMissing('activities', ['title' => 'Spoofed Activity', 'provider_id' => $otherProvider->id]);
    }

    public function test_provider_cannot_view_another_providers_activity(): void
    {
        [$userA, $providerA, $tokenA] = $this->authenticatedProvider();
        [$userB, $providerB, $tokenB] = $this->authenticatedProvider();
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        $activity = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'A Activity'])
            ->json('data');

        // Sanctum's guard caches the resolved user on the underlying RequestGuard
        // instance, which persists across sequential requests within a single test
        // method. Without this, the second request below would silently reuse
        // userA's cached identity instead of genuinely re-resolving tokenB.
        // This has no equivalent in production, where every real HTTP request
        // gets a fresh, uncached guard.
        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->getJson('/api/v1/provider/activities/' . $activity['uuid']);

        $response->assertStatus(403);
    }
    public function test_provider_can_submit_draft_for_review(): void
    {
        [$user, $provider, $token] = $this->authenticatedProvider();
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving', 'status' => 'active']);

        $activity = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'Reef Dive'])
            ->json('data');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities/' . $activity['uuid'] . '/submit-for-review');

        $response->assertOk()->assertJsonPath('data.status', 'pending_review');
    }

    public function test_cannot_submit_non_draft_activity_for_review(): void
    {
        [$user, $provider, $token] = $this->authenticatedProvider();
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving', 'status' => 'active']);

        $activity = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'Reef Dive'])
            ->json('data');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities/' . $activity['uuid'] . '/submit-for-review');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities/' . $activity['uuid'] . '/submit-for-review');

        $response->assertStatus(422);
    }
}
