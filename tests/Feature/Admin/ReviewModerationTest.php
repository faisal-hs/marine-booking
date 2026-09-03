<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(string $role): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

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

        $customerUser = User::factory()->customer()->create();
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

        $review = app(ReviewService::class)->create($customer, $activity, 5, 'Great!');

        return [$activity, $review];
    }

    public function test_admin_with_permission_can_list_reviews(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makeReviewedActivity();

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reviews');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_without_permission_cannot_list_reviews(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reviews');

        $response->assertStatus(403);
    }

    public function test_admin_with_moderate_permission_can_hide_a_review(): void
    {
        $admin = $this->makeAdmin('super_admin');
        [$activity, $review] = $this->makeReviewedActivity();

        $response = $this->actingAs($admin, 'web')
            ->postJson("/api/v1/admin/reviews/{$review->uuid}/hide", ['reason' => 'Inappropriate']);

        $response->assertOk()->assertJsonPath('data.is_hidden', true);
        $this->assertEquals(0, $activity->fresh()->reviews_count);
        $this->assertEquals(0.00, (float) $activity->fresh()->average_rating);
    }

    public function test_support_admin_cannot_hide_a_review(): void
    {
        // support_admin has reviews.view but NOT reviews.moderate.
        $admin = $this->makeAdmin('support_admin');
        [$activity, $review] = $this->makeReviewedActivity();

        $response = $this->actingAs($admin, 'web')->postJson("/api/v1/admin/reviews/{$review->uuid}/hide", []);

        $response->assertStatus(403);
    }

    public function test_admin_can_unhide_a_review(): void
    {
        $admin = $this->makeAdmin('super_admin');
        [$activity, $review] = $this->makeReviewedActivity();
        app(ReviewService::class)->hide($review, 'test');

        $response = $this->actingAs($admin, 'web')->postJson("/api/v1/admin/reviews/{$review->uuid}/unhide");

        $response->assertOk()->assertJsonPath('data.is_hidden', false);
        $this->assertEquals(1, $activity->fresh()->reviews_count);
    }

    public function test_admin_can_view_reviews_index_blade_page(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makeReviewedActivity();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reviews.index'));

        $response->assertOk()->assertSee('Test Activity');
    }

    public function test_unauthenticated_request_cannot_list_reviews(): void
    {
        $response = $this->getJson('/api/v1/admin/reviews');

        $response->assertStatus(401);
    }
}
