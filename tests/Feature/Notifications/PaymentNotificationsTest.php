<?php

namespace Tests\Feature\Notifications;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\Payments\Admin\PaymentFailedAdminNotification;
use App\Notifications\Payments\Admin\PaymentRefundedAdminNotification;
use App\Notifications\Payments\PaymentFailedNotification;
use App\Notifications\Payments\PaymentRefundedNotification;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class PaymentNotificationsTest extends TestCase
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

    protected function makePendingReservation(): array
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

        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);

        return [$reservation, $customerUser];
    }

    public function test_payment_failure_notifies_customer_and_admins(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$reservation, $customerUser] = $this->makePendingReservation();

        app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_failure']);

        NotificationFacade::assertSentTo($customerUser, PaymentFailedNotification::class);
        NotificationFacade::assertSentTo($superAdmin, PaymentFailedAdminNotification::class);
    }

    public function test_payment_failed_notification_channel_matrix(): void
    {
        [$reservation, $customerUser] = $this->makePendingReservation();
        $result = app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_failure']);

        $notification = new PaymentFailedNotification($result['payment']);

        $this->assertEquals(['database', 'fcm', 'sms'], $notification->via($customerUser));
    }

    public function test_payment_refund_notifies_customer_and_admins(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$reservation, $customerUser] = $this->makePendingReservation();
        $result = app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success']);

        app(PaymentService::class)->refund($result['payment']);

        NotificationFacade::assertSentTo($customerUser, PaymentRefundedNotification::class);
        NotificationFacade::assertSentTo($superAdmin, PaymentRefundedAdminNotification::class);
    }

    public function test_payment_refunded_notification_channel_matrix(): void
    {
        [$reservation, $customerUser] = $this->makePendingReservation();
        $result = app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success']);

        $notification = new PaymentRefundedNotification($result['payment']);

        $this->assertEquals(['database', 'fcm', 'mail'], $notification->via($customerUser));
    }

    public function test_no_admin_without_permission_receives_payment_failed_notification(): void
    {
        NotificationFacade::fake();
        $contentAdmin = $this->makeAdmin('content_admin'); // no payments.view
        [$reservation, $customerUser] = $this->makePendingReservation();

        app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_failure']);

        NotificationFacade::assertNotSentTo($contentAdmin, PaymentFailedAdminNotification::class);
    }
}
