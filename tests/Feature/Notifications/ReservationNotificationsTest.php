<?php

namespace Tests\Feature\Notifications;

use App\Enums\ReservationStatus;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Reservations\Admin\NewReservationAdminNotification;
use App\Notifications\Reservations\Admin\ReservationCancelledAdminNotification;
use App\Notifications\Reservations\Admin\ReservationExpiredAdminNotification;
use App\Notifications\Reservations\ReservationCancelledNotification;
use App\Notifications\Reservations\ReservationConfirmedNotification;
use App\Notifications\Reservations\ReservationCreatedNotification;
use App\Notifications\Reservations\ReservationExpiredNotification;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class ReservationNotificationsTest extends TestCase
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

    protected function makeBookableSetup(): array
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

        return [$activity, $option, $customer, $customerUser];
    }

    // --- Created ---

    public function test_creating_a_reservation_notifies_the_customer_and_admins_with_permission(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        $contentAdmin = $this->makeAdmin('content_admin'); // does NOT have reservations.view
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();

        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);

        NotificationFacade::assertSentTo($customerUser, ReservationCreatedNotification::class);
        NotificationFacade::assertSentTo($superAdmin, \App\Notifications\Reservations\Admin\NewReservationAdminNotification::class);
        NotificationFacade::assertNotSentTo($contentAdmin, \App\Notifications\Reservations\Admin\NewReservationAdminNotification::class);
    }

    public function test_reservation_created_notification_uses_the_correct_channel_matrix(): void
    {
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);

        $notification = new ReservationCreatedNotification($reservation);

        $this->assertEquals(['database', 'fcm'], $notification->via($customerUser));
    }

    public function test_created_notification_is_actually_persisted_to_the_database_channel(): void
    {
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();

        app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $customerUser->id,
            'type' => ReservationCreatedNotification::class,
        ]);
    }

    // --- Confirmed ---

    public function test_confirming_a_reservation_notifies_only_the_customer_not_admins(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);

        app(ReservationService::class)->confirmPayment($reservation);

        NotificationFacade::assertSentTo($customerUser, ReservationConfirmedNotification::class);
        NotificationFacade::assertNotSentTo($superAdmin, ReservationConfirmedNotification::class);
    }

    public function test_reservation_confirmed_notification_uses_the_full_channel_matrix(): void
    {
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);

        $notification = new ReservationConfirmedNotification($reservation);

        $this->assertEquals(['database', 'fcm', 'sms', 'mail'], $notification->via($customerUser));
    }

    // --- Cancelled ---

    public function test_cancelling_a_reservation_notifies_customer_and_admins(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);

        app(ReservationService::class)->cancel($reservation, 'Change of plans');

        NotificationFacade::assertSentTo($customerUser, ReservationCancelledNotification::class);
        NotificationFacade::assertSentTo($superAdmin, ReservationCancelledAdminNotification::class);
    }

    // --- Expired ---

    public function test_expiring_stale_holds_notifies_customer_and_admins_for_each_expired_reservation(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);
        $reservation->update(['hold_expires_at' => now()->subMinute()]);

        $count = app(ReservationService::class)->expireStaleHolds();

        $this->assertEquals(1, $count);
        NotificationFacade::assertSentTo($customerUser, ReservationExpiredNotification::class);
        NotificationFacade::assertSentTo($superAdmin, ReservationExpiredAdminNotification::class);
    }

    public function test_expiring_multiple_stale_holds_notifies_for_each_one_independently(): void
    {
        NotificationFacade::fake();
        [$activity, $option, $customerA, $customerUserA] = $this->makeBookableSetup();
        [$activity2, $option2, $customerB, $customerUserB] = $this->makeBookableSetup();

        $reservationA = app(ReservationService::class)->create($customerA, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);
        $reservationB = app(ReservationService::class)->create($customerB, $activity2, $option2, null, Carbon::parse('2026-09-06'), ['adult' => 1]);
        $reservationA->update(['hold_expires_at' => now()->subMinute()]);
        $reservationB->update(['hold_expires_at' => now()->subMinute()]);

        $count = app(ReservationService::class)->expireStaleHolds();

        $this->assertEquals(2, $count);
        NotificationFacade::assertSentTo($customerUserA, ReservationExpiredNotification::class);
        NotificationFacade::assertSentTo($customerUserB, ReservationExpiredNotification::class);
    }

    // --- Idempotency (via the underlying dispatch service) ---

    public function test_notification_dispatch_for_the_same_reservation_event_is_idempotent(): void
    {
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);

        // No admin exists in this test (RolesAndPermissionsSeeder only
        // seeds roles/permissions, never actual User accounts), so the
        // original create() call above sent exactly 1 notification (to
        // the customer only). Firing the SAME event again simulates an
        // accidental duplicate dispatch (e.g. a retried job) — it must
        // remain exactly 1, not become 2.
        $this->assertDatabaseCount('notifications', 1);

        event(new \App\Events\ReservationCreated($reservation));

        $this->assertDatabaseCount('notifications', 1);
    }
}
