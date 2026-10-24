<?php

namespace Tests\Feature\Notifications;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RelativeDates;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;
    use RelativeDates;

    protected function makeCustomerWithNotification(): array
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
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $token = $customerUser->createToken('test', ['customer'])->plainTextToken;

        // Creating a reservation naturally generates a real 'database'
        // channel notification for this customer via the existing
        // ReservationCreated event/listener wiring — no need for a
        // separate notification-creation helper.
        app(ReservationService::class)->create($customer, $activity, $option, null, $this->futureSaturday(), ['adult' => 1]);

        return [$customerUser, $token];
    }

    public function test_customer_can_list_own_notifications(): void
    {
        [$customerUser, $token] = $this->makeCustomerWithNotification();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/customer/notifications');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertEquals('reservation.created', $response->json('data.0.type'));
    }

    public function test_notification_starts_unread(): void
    {
        [$customerUser, $token] = $this->makeCustomerWithNotification();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/customer/notifications');

        $this->assertNull($response->json('data.0.read_at'));
    }

    public function test_unread_count_reflects_unread_notifications(): void
    {
        [$customerUser, $token] = $this->makeCustomerWithNotification();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/customer/notifications/unread-count');

        $response->assertOk()->assertJsonPath('data.count', 1);
    }

    public function test_customer_can_mark_a_single_notification_as_read(): void
    {
        [$customerUser, $token] = $this->makeCustomerWithNotification();
        $notificationId = $customerUser->notifications()->first()->id;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/notifications/{$notificationId}/read");

        $response->assertOk();
        $this->assertNotNull($response->json('data.read_at'));

        $unreadCountResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/customer/notifications/unread-count');
        $unreadCountResponse->assertJsonPath('data.count', 0);
    }

    public function test_customer_can_mark_all_notifications_as_read(): void
    {
        [$customerUser, $token] = $this->makeCustomerWithNotification();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/customer/notifications/read-all');

        $response->assertOk();

        $unreadCountResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/customer/notifications/unread-count');
        $unreadCountResponse->assertJsonPath('data.count', 0);
    }

    public function test_customer_cannot_mark_another_customers_notification_as_read(): void
    {
        [$customerUserA, $tokenA] = $this->makeCustomerWithNotification();
        [$customerUserB, $tokenB] = $this->makeCustomerWithNotification();
        $notificationIdA = $customerUserA->notifications()->first()->id;

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson("/api/v1/customer/notifications/{$notificationIdA}/read");

        $response->assertStatus(404);
    }

    public function test_unauthenticated_request_cannot_list_notifications(): void
    {
        $response = $this->getJson('/api/v1/customer/notifications');

        $response->assertStatus(401);
    }

    public function test_provider_can_list_own_notifications_via_the_same_shared_endpoint(): void
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'pending_review',
        ]);
        app(\App\Services\ActivityModerationService::class)->approve($activity);

        $token = $providerUser->createToken('test', ['provider'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/provider/notifications');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertEquals('activity.approved', $response->json('data.0.type'));
    }
}
