<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivityImageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function authenticatedProviderWithActivity(): array
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
        ]);

        return [$user, $provider, $token, $activity];
    }

    public function test_first_uploaded_image_becomes_cover_automatically(): void
    {
        [$user, $provider, $token, $activity] = $this->authenticatedProviderWithActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/images", [
                'images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('activity_images', ['activity_id' => $activity->id, 'is_cover' => true]);
        $this->assertEquals(1, $activity->images()->where('is_cover', true)->count());
    }

    public function test_deleting_cover_image_promotes_next_image(): void
    {
        [$user, $provider, $token, $activity] = $this->authenticatedProviderWithActivity();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/images", [
                'images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
            ]);

        $cover = $activity->images()->where('is_cover', true)->first();
        $remaining = $activity->images()->where('id', '!=', $cover->id)->first();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/provider/activities/{$activity->uuid}/images/{$cover->uuid}")
            ->assertOk();

        $this->assertTrue($remaining->fresh()->is_cover);
        Storage::disk('public')->assertMissing($cover->path);
    }

    public function test_deleting_last_image_leaves_no_cover(): void
    {
        [$user, $provider, $token, $activity] = $this->authenticatedProviderWithActivity();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/images", [
                'images' => [UploadedFile::fake()->image('one.jpg')],
            ]);

        $image = $activity->images()->first();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/provider/activities/{$activity->uuid}/images/{$image->uuid}")
            ->assertOk();

        $this->assertEquals(0, $activity->images()->count());
    }

    public function test_provider_cannot_upload_images_to_another_providers_activity(): void
    {
        [$userA, $providerA, $tokenA, $activityA] = $this->authenticatedProviderWithActivity();
        [$userB, $providerB, $tokenB, $activityB] = $this->authenticatedProviderWithActivity();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/v1/provider/activities/{$activityA->uuid}/images", [
                'images' => [UploadedFile::fake()->image('sneaky.jpg')],
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_delete_image_belonging_to_a_different_activity_via_mismatched_uuids(): void
    {
        [$user, $provider, $token, $activityA] = $this->authenticatedProviderWithActivity();

        $categoryB = Category::create(['name' => 'Fishing', 'slug' => 'fishing-' . uniqid(), 'status' => 'active']);
        $activityB = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $categoryB->id,
            'title' => 'Second Activity',
            'slug' => 'second-activity-' . uniqid(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activityB->uuid}/images", [
                'images' => [UploadedFile::fake()->image('b.jpg')],
            ]);

        $imageBelongingToB = $activityB->images()->first();

        // Same provider owns both activities (so ActivityPolicy::update passes),
        // but the image doesn't belong to activityA — must still be rejected.
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/provider/activities/{$activityA->uuid}/images/{$imageBelongingToB->uuid}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('activity_images', ['id' => $imageBelongingToB->id]);
    }

    public function test_reorder_updates_sort_order(): void
    {
        [$user, $provider, $token, $activity] = $this->authenticatedProviderWithActivity();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/provider/activities/{$activity->uuid}/images", [
                'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ]);

        $images = $activity->images()->orderBy('sort_order')->get();
        $reversedOrder = $images->pluck('uuid')->reverse()->values()->toArray();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/images/reorder", [
                'order' => $reversedOrder,
            ])
            ->assertOk();

        $this->assertEquals($reversedOrder[0], $images[1]->uuid);
        $this->assertEquals(0, $images[1]->fresh()->sort_order);
        $this->assertEquals(1, $images[0]->fresh()->sort_order);
    }
}
