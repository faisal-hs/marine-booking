<?php

namespace Tests\Feature\Notifications;

use App\Contracts\Fcm\FcmGatewayInterface;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\Activities\ActivityApprovedNotification;
use App\Notifications\Payments\PaymentFailedNotification;
use App\Notifications\Providers\ProviderVerifiedNotification;
use App\Notifications\Reservations\Admin\NewReservationAdminNotification;
use App\Notifications\Reservations\ReservationConfirmedNotification;
use App\Notifications\Reservations\ReservationCreatedNotification;
use App\Services\NotificationDispatchService;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

/** Throws while rendering its database payload, standing in for any channel failure. */
class ExplodingTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        throw new \RuntimeException('channel exploded');
    }
}

class HealthyTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['message' => 'ok'];
    }
}

/**
 * Proves the two guarantees added to notification dispatch:
 *  1. A failing channel can never break, or lose, the business operation
 *     that triggered the notification (and the dedup slot is released so
 *     the notification can still be delivered later).
 *  2. Notifications that reach external services are queued (one job per
 *     channel); database-only admin notifications are not.
 */
class NotificationFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(string $role = 'super_admin'): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    /** @return array{0: Activity, 1: \App\Models\ActivityBookingOption, 2: Customer, 3: User} */
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
        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        return [$activity, $option, $customer, $customerUser];
    }

    /** Computed from today, never hardcoded, and always a Saturday to match the schedule period above. */
    protected function bookableDate(): Carbon
    {
        return Carbon::now()->next(Carbon::SATURDAY);
    }

    // --- NotificationDispatchService: failure semantics ---

    public function test_a_failing_notification_is_swallowed_and_reported_not_thrown(): void
    {
        Exceptions::fake();
        $user = User::factory()->customer()->create();

        $result = app(NotificationDispatchService::class)
            ->dispatchOnce('test.fail:1', $user, new ExplodingTestNotification());

        $this->assertFalse($result);
        $this->assertDatabaseCount('notifications', 0);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_a_failed_dispatch_releases_the_dedup_slot_so_a_retry_can_succeed(): void
    {
        Exceptions::fake();
        $user = User::factory()->customer()->create();
        $service = app(NotificationDispatchService::class);

        $first = $service->dispatchOnce('test.retry:1', $user, new ExplodingTestNotification());

        // The slot must be free again, otherwise this notification would be lost forever.
        $this->assertFalse($first);
        $this->assertDatabaseMissing('notification_dispatch_log', ['event_key' => 'test.retry:1']);

        $second = $service->dispatchOnce('test.retry:1', $user, new HealthyTestNotification());

        $this->assertTrue($second);
        $this->assertDatabaseHas('notification_dispatch_log', ['event_key' => 'test.retry:1']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_successful_dispatch_keeps_its_slot_and_still_blocks_duplicates(): void
    {
        $user = User::factory()->customer()->create();
        $service = app(NotificationDispatchService::class);

        $first = $service->dispatchOnce('test.once:1', $user, new HealthyTestNotification());
        $second = $service->dispatchOnce('test.once:1', $user, new HealthyTestNotification());

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertDatabaseCount('notifications', 1);
    }

    // --- End to end: a broken push provider must not break a paid booking ---

    public function test_a_failing_push_gateway_does_not_break_reservation_creation_or_payment_confirmation(): void
    {
        Exceptions::fake();

        $this->app->bind(FcmGatewayInterface::class, fn() => new class implements FcmGatewayInterface
        {
            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                throw new \RuntimeException('FCM is down');
            }
        });

        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        Device::create(['user_id' => $customerUser->id, 'fcm_token' => 'token-1', 'platform' => 'android']);

        // 1) Creating the reservation fires ReservationCreated -> a push that throws.
        $reservation = app(ReservationService::class)
            ->create($customer, $activity, $option, null, $this->bookableDate(), ['adult' => 1]);

        $this->assertNotNull($reservation->id);

        // 2) Paying fires ReservationConfirmed -> another push that throws. The payment
        //    path (a webhook in production) must still complete normally.
        $result = app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success']);

        $this->assertEquals(PaymentStatus::Succeeded, $result['payment']->status);
        $this->assertEquals(ReservationStatus::Confirmed, $reservation->fresh()->status);

        // The failures were reported, and their dedup slots released for a later retry.
        Exceptions::assertReported(\RuntimeException::class);
        $this->assertDatabaseMissing('notification_dispatch_log', [
            'notifiable_id' => $customerUser->id,
            'event_key' => "reservation.confirmed:{$reservation->id}",
        ]);
    }

    // --- Queueing: external-channel notifications are queued, admin ones are not ---

    public function test_customer_notifications_are_queued_one_job_per_channel_while_admin_ones_are_not(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin('super_admin');
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();

        app(ReservationService::class)
            ->create($customer, $activity, $option, null, $this->bookableDate(), ['adult' => 1]);

        // Admin: database-only, delivered immediately, nothing queued for them.
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'type' => NewReservationAdminNotification::class,
        ]);

        // Customer: handed to the queue (database + fcm), nothing delivered yet.
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $customerUser->id]);

        $jobs = Queue::pushed(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs, 'ReservationCreated should queue exactly one job per channel (database, fcm).');
        $this->assertTrue($jobs->every(fn($job) => $job->notification instanceof ReservationCreatedNotification));
        $this->assertEquals(['database', 'fcm'], $jobs->map(fn($job) => $job->channels[0])->sort()->values()->all());
    }

    public function test_queued_notifications_still_deliver_when_the_queue_runs(): void
    {
        // Default test queue connection is sync, so the job runs inline: proves the
        // queued path (including model re-hydration) produces the in-app notification.
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();

        app(ReservationService::class)
            ->create($customer, $activity, $option, null, $this->bookableDate(), ['adult' => 1]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $customerUser->id,
            'type' => ReservationCreatedNotification::class,
        ]);
    }

    public function test_a_queued_notification_survives_a_serialization_round_trip(): void
    {
        Queue::fake();
        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        $reservation = app(ReservationService::class)
            ->create($customer, $activity, $option, null, $this->bookableDate(), ['adult' => 1]);

        $original = new ReservationConfirmedNotification($reservation);
        $restored = unserialize(serialize($original));

        $this->assertInstanceOf(ShouldQueue::class, $restored);
        $this->assertEquals($original->toArray($customerUser), $restored->toArray($customerUser));
    }

    public function test_every_external_channel_family_is_queued_with_the_shared_retry_policy(): void
    {
        $queued = [
            ReservationConfirmedNotification::class,
            PaymentFailedNotification::class,
            ActivityApprovedNotification::class,
            ProviderVerifiedNotification::class,
        ];

        foreach ($queued as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldQueue::class), "{$class} should be queued.");

            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $this->assertSame(3, $defaults['tries'], "{$class} should retry 3 times.");
            $this->assertSame([30, 120], $defaults['backoff'], "{$class} should back off 30s then 120s.");
        }
    }

    public function test_database_only_admin_notifications_are_not_queued(): void
    {
        $this->assertFalse(is_subclass_of(NewReservationAdminNotification::class, ShouldQueue::class));
    }

    // --- Real database queue + worker: per-channel retry, no duplicate in-app notification ---

    /**
     * Gateway that fails its first $failFirst calls, then succeeds.
     */
    protected function flakyFcmGateway(int $failFirst): FcmGatewayInterface
    {
        $gateway = new class($failFirst) implements FcmGatewayInterface
        {
            public int $calls = 0;

            public function __construct(public int $failFirst) {}

            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                $this->calls++;

                if ($this->calls <= $this->failFirst) {
                    throw new \RuntimeException('FCM is down');
                }

                return true;
            }
        };

        $this->app->instance(FcmGatewayInterface::class, $gateway);

        return $gateway;
    }

    protected function runQueueWorker(): void
    {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
    }

    public function test_a_flaky_channel_is_retried_on_its_own_and_never_repeats_the_in_app_notification(): void
    {
        config(['queue.default' => 'database']);
        $gateway = $this->flakyFcmGateway(failFirst: 2);

        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        Device::create(['user_id' => $customerUser->id, 'fcm_token' => 'token-1', 'platform' => 'android']);

        app(ReservationService::class)
            ->create($customer, $activity, $option, null, $this->bookableDate(), ['adult' => 1]);

        // Handed to the real queue: one job per channel (database, fcm), nothing delivered yet.
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $customerUser->id]);

        // Attempt 1: the in-app notification is delivered, the push fails and is released with backoff.
        $this->runQueueWorker();
        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('notifications', 1);

        // Attempt 2 (after the 30s backoff): the push fails again.
        $this->travel(31)->seconds();
        $this->runQueueWorker();
        $this->assertSame(2, $gateway->calls);

        // Attempt 3 (after the 120s backoff): the push finally succeeds.
        $this->travel(121)->seconds();
        $this->runQueueWorker();
        $this->assertSame(3, $gateway->calls);

        // The push was retried 3 times, yet the in-app notification was created exactly once
        // and nothing was left behind as a permanent failure.
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertDatabaseHas('notification_dispatch_log', [
            'notifiable_id' => $customerUser->id,
            'event_key' => 'reservation.created:' . \App\Models\Reservation::first()->id,
        ]);
    }

    public function test_a_channel_that_keeps_failing_ends_up_in_failed_jobs_after_three_tries(): void
    {
        config(['queue.default' => 'database']);
        $gateway = $this->flakyFcmGateway(failFirst: 99);

        [$activity, $option, $customer, $customerUser] = $this->makeBookableSetup();
        Device::create(['user_id' => $customerUser->id, 'fcm_token' => 'token-1', 'platform' => 'android']);

        app(ReservationService::class)
            ->create($customer, $activity, $option, null, $this->bookableDate(), ['adult' => 1]);

        $this->runQueueWorker();
        $this->travel(31)->seconds();
        $this->runQueueWorker();
        $this->travel(121)->seconds();
        $this->runQueueWorker();

        $this->assertSame(3, $gateway->calls, 'Exactly 3 attempts, matching the shared $tries policy.');
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'Only the push job fails permanently.');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertDatabaseCount('notifications', 1);
    }
}
