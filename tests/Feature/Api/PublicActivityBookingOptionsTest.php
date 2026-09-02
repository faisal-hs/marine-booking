<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicActivityBookingOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function makePublishedActivity(): Activity
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        return Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
    }

    public function test_public_show_exposes_active_booking_options(): void
    {
        $activity = $this->makePublishedActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.booking_options')
            ->assertJsonPath('data.booking_options.0.name', 'Standard');
    }

    public function test_public_show_hides_inactive_booking_options(): void
    {
        $activity = $this->makePublishedActivity();
        $activity->bookingOptions()->create(['name' => 'Active Option', 'slug' => 'active-option', 'booking_model' => 'per_trip', 'is_active' => true]);
        $activity->bookingOptions()->create(['name' => 'Inactive Option', 'slug' => 'inactive-option', 'booking_model' => 'per_trip', 'is_active' => false]);

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.booking_options')
            ->assertJsonPath('data.booking_options.0.name', 'Active Option');
    }

    public function test_public_show_hides_inactive_pricing_rules_within_an_active_option(): void
    {
        $activity = $this->makePublishedActivity();
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard', 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500, 'is_active' => true]);
        $option->pricingRules()->create(['participant_type' => 'child', 'price' => 750, 'is_active' => false]);

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}");

        $response->assertOk()->assertJsonCount(1, 'data.booking_options.0.pricing_rules');
    }

    public function test_public_show_does_not_expose_rejection_reason_or_raw_overrides(): void
    {
        $activity = $this->makePublishedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->slug}");

        $response->assertOk();
        $this->assertArrayNotHasKey('rejection_reason', $response->json('data'));
        $this->assertArrayNotHasKey('overrides', $response->json('data'));
        $this->assertArrayNotHasKey('schedule', $response->json('data'));
    }
}
