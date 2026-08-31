<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function makeActivity(string $status, string $title = 'Test Activity'): Activity
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Provider', 'slug' => 'provider-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        return Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title) . '-' . uniqid(),
            'status' => $status,
        ]);
    }

    public function test_only_published_activities_appear_in_public_listing(): void
    {
        $this->makeActivity('published', 'Visible Activity');
        $this->makeActivity('draft', 'Hidden Draft');
        $this->makeActivity('pending_review', 'Hidden Pending');

        $response = $this->getJson('/api/v1/public/activities');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Visible Activity');
    }

    public function test_draft_activity_returns_404_on_public_show(): void
    {
        $activity = $this->makeActivity('draft');

        $response = $this->getJson('/api/v1/public/activities/' . $activity->slug);

        $response->assertStatus(404);
    }

    public function test_published_activity_is_visible_by_slug(): void
    {
        $activity = $this->makeActivity('published');

        $response = $this->getJson('/api/v1/public/activities/' . $activity->slug);

        $response->assertOk()->assertJsonPath('data.slug', $activity->slug);
    }
}
