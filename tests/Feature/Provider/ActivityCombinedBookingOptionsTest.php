<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityCombinedBookingOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProviderToken(): array
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $token = $user->createToken('test', ['provider'])->plainTextToken;
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        return [$token, $category, $provider];
    }

    public function test_combined_create_accepts_booking_options_with_nested_pricing_rules(): void
    {
        [$token, $category] = $this->makeProviderToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities', [
                'category_id' => $category->id,
                'title' => 'Combined Test Activity',
                'booking_options' => [
                    [
                        'name' => 'Standard',
                        'booking_model' => 'per_person',
                        'pricing_rules' => [
                            ['participant_type' => 'adult', 'price' => 1500],
                            ['participant_type' => 'child', 'price' => 750],
                        ],
                    ],
                ],
            ]);

        $response->assertCreated();
        $this->assertCount(1, $response->json('data.booking_options'));
        $this->assertCount(2, $response->json('data.booking_options.0.pricing_rules'));
        $this->assertEquals('per_person', $response->json('data.booking_options.0.booking_model'));

        $activity = Activity::where('title', 'Combined Test Activity')->first();
        $this->assertDatabaseHas('activity_booking_options', ['activity_id' => $activity->id, 'name' => 'Standard']);
        $this->assertDatabaseHas('activity_pricing_rules', ['participant_type' => 'adult', 'price' => 1500.00]);
    }

    public function test_combined_create_rejects_per_hour_option_missing_duration_bounds(): void
    {
        [$token, $category] = $this->makeProviderToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities', [
                'category_id' => $category->id,
                'title' => 'Combined Test Activity',
                'booking_options' => [
                    ['name' => 'Private Yacht', 'booking_model' => 'per_hour'],
                ],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['booking_options.0.min_duration', 'booking_options.0.max_duration']);
    }

    public function test_combined_create_accepts_per_hour_option_with_duration_bounds(): void
    {
        [$token, $category] = $this->makeProviderToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities', [
                'category_id' => $category->id,
                'title' => 'Combined Test Activity',
                'booking_options' => [
                    [
                        'name' => 'Private Yacht',
                        'booking_model' => 'per_hour',
                        'min_duration' => 2,
                        'max_duration' => 8,
                        'pricing_rules' => [['price' => 5000]],
                    ],
                ],
            ]);

        $response->assertCreated();
    }

    public function test_combined_create_no_longer_requires_price_on_schedule_items(): void
    {
        // Regression test for the Step 5 fix: the combined endpoint's own
        // nested schedule validation still required 'price' after Module 08
        // Step 2 removed it everywhere else. This proves schedule items
        // work correctly WITHOUT a price field via this endpoint too.
        [$token, $category] = $this->makeProviderToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/provider/activities', [
                'category_id' => $category->id,
                'title' => 'Combined Test Activity',
                'schedule' => [
                    ['day_of_week' => 'saturday', 'start_time' => '09:00', 'end_time' => '13:00'],
                ],
            ]);

        $response->assertCreated();
        $this->assertCount(1, $response->json('data.schedule'));
    }

    public function test_combined_update_fully_replaces_booking_options(): void
    {
        [$token, $category, $provider] = $this->makeProviderToken();

        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Existing Activity',
            'slug' => 'existing-activity-' . uniqid(),
        ]);
        $oldOption = $activity->bookingOptions()->create(['name' => 'Old Option', 'slug' => 'old-option', 'booking_model' => 'per_trip']);
        $oldOption->pricingRules()->create(['price' => 8000]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}", [
                'booking_options' => [
                    ['name' => 'New Option', 'booking_model' => 'per_unit', 'pricing_rules' => [['price' => 1000]]],
                ],
            ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.booking_options'));
        $this->assertEquals('New Option', $response->json('data.booking_options.0.name'));

        $this->assertSoftDeleted('activity_booking_options', ['id' => $oldOption->id]);
        $this->assertDatabaseMissing('activity_pricing_rules', ['activity_booking_option_id' => $oldOption->id]);
    }

    public function test_combined_update_without_booking_options_key_leaves_existing_options_untouched(): void
    {
        [$token, $category, $provider] = $this->makeProviderToken();

        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Existing Activity',
            'slug' => 'existing-activity-' . uniqid(),
        ]);
        $option = $activity->bookingOptions()->create(['name' => 'Kept Option', 'slug' => 'kept-option', 'booking_model' => 'per_trip']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}", ['title' => 'Renamed Activity']);

        $response->assertOk();
        $this->assertDatabaseHas('activity_booking_options', ['id' => $option->id, 'deleted_at' => null]);
    }
}
