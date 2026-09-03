<?php

namespace Tests\Feature\Public;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeReviewedActivity(): array
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);

        $customerUser = User::factory()->customer()->create(['name' => 'Jane Reviewer']);
        $customer = Customer::create(['user_id' => $customerUser->id]);

        Reservation::create([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => now()->subDay()->toDateString(),
            'status' => 'completed',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 1500,
            'total' => 1500,
            'pricing_breakdown' => [],
        ]);

        $review = app(ReviewService::class)->create($customer, $activity, 4, 'Really enjoyed it!');

        return [$activity, $review];
    }

    public function test_public_can_list_visible_reviews_for_an_activity(): void
    {
        [$activity, $review] = $this->makeReviewedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->uuid}/reviews");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertEquals('Jane Reviewer', $response->json('data.0.customer_name'));
        $this->assertEquals(4, $response->json('data.0.rating'));
    }

    public function test_hidden_reviews_do_not_appear_in_the_public_listing(): void
    {
        [$activity, $review] = $this->makeReviewedActivity();
        app(ReviewService::class)->hide($review, 'test');

        $response = $this->getJson("/api/v1/public/activities/{$activity->uuid}/reviews");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_public_activity_detail_includes_rating_aggregate(): void
    {
        [$activity, $review] = $this->makeReviewedActivity();

        $response = $this->getJson("/api/v1/public/activities/{$activity->uuid}");

        $response->assertOk()->assertJsonPath('data.reviews_count', 1);
        $this->assertEquals(4.0, $response->json('data.average_rating'));
    }

    public function test_public_activity_list_includes_rating_aggregate(): void
    {
        [$activity, $review] = $this->makeReviewedActivity();

        $response = $this->getJson('/api/v1/public/activities');

        $response->assertOk();
        $matching = collect($response->json('data'))->firstWhere('id', $activity->id);
        $this->assertEquals(4.0, $matching['average_rating']);
        $this->assertEquals(1, $matching['reviews_count']);
    }

    public function test_reviews_endpoint_404s_for_a_non_published_activity(): void
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $draftActivity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Draft',
            'slug' => 'draft-' . uniqid(),
            'status' => 'draft',
        ]);

        $response = $this->getJson("/api/v1/public/activities/{$draftActivity->uuid}/reviews");

        $response->assertStatus(404);
    }
}
