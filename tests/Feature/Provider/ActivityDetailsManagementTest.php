<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityDetailsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProviderActivity(): array
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

    public function test_provider_can_sync_options(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/options", [
                'options' => [
                    ['name' => 'Underwater Photos', 'extra_price' => 25, 'is_required' => false],
                    ['name' => 'Private Instructor', 'extra_price' => 80, 'is_required' => false, 'max_quantity' => 1],
                ],
            ]);

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEquals(2, $activity->options()->count());
        $this->assertDatabaseHas('activity_options', ['name' => 'Underwater Photos', 'extra_price' => 25.00]);
    }

    public function test_syncing_options_replaces_previous_list_entirely(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/options", [
                'options' => [['name' => 'Old Option']],
            ]);

        $oldOptionId = $activity->options()->first()->id;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/options", [
                'options' => [['name' => 'New Option']],
            ]);

        $this->assertEquals(1, $activity->options()->count());
        $this->assertDatabaseMissing('activity_options', ['id' => $oldOptionId]);
        $this->assertDatabaseHas('activity_options', ['name' => 'New Option']);
    }

    public function test_provider_can_sync_requirements(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/requirements", [
                'requirements' => [
                    ['text' => 'Must know how to swim', 'is_mandatory' => true],
                    ['text' => 'Minimum age 12', 'is_mandatory' => true],
                ],
            ]);

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEquals(2, $activity->requirements()->count());
    }

    public function test_provider_can_sync_inclusions_and_exclusions(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/inclusions", [
                'inclusions' => [
                    ['type' => 'included', 'description' => 'Wetsuit'],
                    ['type' => 'excluded', 'description' => 'Underwater camera'],
                ],
            ]);

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('activity_inclusions', ['type' => 'included', 'description' => 'Wetsuit']);
        $this->assertDatabaseHas('activity_inclusions', ['type' => 'excluded', 'description' => 'Underwater camera']);
    }

    public function test_invalid_inclusion_type_is_rejected(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/inclusions", [
                'inclusions' => [['type' => 'maybe', 'description' => 'Unclear item']],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['inclusions.0.type']);
    }

    public function test_provider_cannot_sync_options_on_another_providers_activity(): void
    {
        [$userA, $providerA, $tokenA, $activityA] = $this->makeProviderActivity();
        [$userB, $providerB, $tokenB, $activityB] = $this->makeProviderActivity();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->putJson("/api/v1/provider/activities/{$activityA->uuid}/options", [
                'options' => [['name' => 'Sneaky Option']],
            ]);

        $response->assertStatus(403);
        $this->assertEquals(0, $activityA->options()->count());
    }

    public function test_empty_array_clears_the_list(): void
    {
        [$user, $provider, $token, $activity] = $this->makeProviderActivity();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/requirements", [
                'requirements' => [['text' => 'Something']],
            ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}/requirements", [
                'requirements' => [],
            ])->assertOk();

        $this->assertEquals(0, $activity->requirements()->count());
    }
}
