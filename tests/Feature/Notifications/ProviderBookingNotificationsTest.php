<?php

namespace Tests\Feature\Notifications;

use App\Contracts\Fcm\FcmGatewayInterface;
use App\Events\ReservationConfirmed;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Activities\ActivitySentBackToReviewNotification;
use App\Notifications\Reservations\Provider\BookingCancelledProviderNotification;
use App\Notifications\Reservations\Provider\NewBookingProviderNotification;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\Concerns\RelativeDates;
use Tests\TestCase;

/**
 * Providers used to hear about moderation and their own verification, and nothing
 * else: a booking, a cancellation, a payment on their activity never reached them.
 * They now get an in-app and push alert when a booking is CONFIRMED (paid) and when a
 * confirmed booking is cancelled. An unpaid pending hold is not worth an alert, and
 * the alerts carry no customer name or contact, because the provider API shows none.
 */
class ProviderBookingNotificationsTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;
    use RelativeDates;

    /** @return array{providerUser: User, customerUser: User, customer: Customer, reservation: Reservation} */
    private function pendingReservation(): array
    {
        [$providerUser, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published');
        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-'.uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create(['name' => 'Layla Hassan', 'phone' => '0533333333']);
        $customer = Customer::create(['user_id' => $customerUser->id]);

        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, $this->futureSaturday(), ['adult' => 2]);

        return compact('providerUser', 'customerUser', 'customer', 'reservation');
    }

    private function confirm(Reservation $reservation): Reservation
    {
        app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success']);

        return $reservation->fresh();
    }

    private function providerNotifications(int $userId, string $class)
    {
        return DB::table('notifications')->where('notifiable_id', $userId)->where('type', $class)->get();
    }

    public function test_the_provider_is_told_when_a_booking_is_confirmed(): void
    {
        ['providerUser' => $providerUser, 'customerUser' => $customerUser, 'reservation' => $reservation] = $this->pendingReservation();

        $this->confirm($reservation);

        $rows = $this->providerNotifications($providerUser->id, NewBookingProviderNotification::class);
        $this->assertCount(1, $rows);

        $data = json_decode($rows->first()->data, true);
        $this->assertSame('provider.booking.confirmed', $data['type']);
        $this->assertSame('Reef Dive', $data['activity_title']);
        $this->assertSame($this->futureSaturday()->toDateString(), $data['reservation_date']);
        $this->assertSame(['adult' => 2], $data['participants']);

        // The customer is still told, as before.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $customerUser->id, 'type' => \App\Notifications\Reservations\ReservationConfirmedNotification::class]);
    }

    public function test_the_alert_carries_no_customer_identity(): void
    {
        ['providerUser' => $providerUser, 'reservation' => $reservation] = $this->pendingReservation();

        $this->confirm($reservation);

        $payload = (string) $this->providerNotifications($providerUser->id, NewBookingProviderNotification::class)->first()->data;
        $this->assertStringNotContainsString('Layla', $payload);
        $this->assertStringNotContainsString('0533333333', $payload);
        $this->assertStringNotContainsString('customer', strtolower(implode(',', array_keys(json_decode($payload, true)))));
    }

    public function test_a_pending_unpaid_booking_does_not_alert_the_provider(): void
    {
        ['providerUser' => $providerUser] = $this->pendingReservation();

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $providerUser->id)->count());
    }

    public function test_cancelling_a_pending_booking_does_not_alert_the_provider(): void
    {
        ['providerUser' => $providerUser, 'reservation' => $reservation] = $this->pendingReservation();

        app(ReservationService::class)->cancel($reservation, 'Changed my mind');

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $providerUser->id)->count());
    }

    public function test_cancelling_a_confirmed_booking_alerts_the_provider(): void
    {
        ['providerUser' => $providerUser, 'reservation' => $reservation] = $this->pendingReservation();
        $reservation = $this->confirm($reservation);

        app(ReservationService::class)->cancel($reservation, 'Cannot make it');

        $rows = $this->providerNotifications($providerUser->id, BookingCancelledProviderNotification::class);
        $this->assertCount(1, $rows);
        $this->assertSame('provider.booking.cancelled', json_decode($rows->first()->data, true)['type']);
    }

    public function test_an_expired_hold_does_not_alert_the_provider(): void
    {
        ['providerUser' => $providerUser, 'reservation' => $reservation] = $this->pendingReservation();
        $reservation->update(['hold_expires_at' => now()->subMinute()]);

        app(ReservationService::class)->expireStaleHolds();

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $providerUser->id)->count());
    }

    public function test_handling_the_same_confirmation_twice_alerts_only_once(): void
    {
        ['providerUser' => $providerUser, 'reservation' => $reservation] = $this->pendingReservation();
        $reservation = $this->confirm($reservation);

        event(new ReservationConfirmed($reservation));

        $this->assertCount(1, $this->providerNotifications($providerUser->id, NewBookingProviderNotification::class));
    }

    public function test_only_the_provider_who_owns_the_activity_is_alerted(): void
    {
        ['reservation' => $reservation] = $this->pendingReservation();
        [$otherProviderUser] = $this->makeProvider('verified');

        $this->confirm($reservation);

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $otherProviderUser->id)->count());
    }

    public function test_the_provider_also_gets_a_push_on_their_device(): void
    {
        // Bound BEFORE anything is booked: the push channel keeps the gateway it was first
        // built with, and creating the reservation already sends the customer a push.
        $gateway = new class implements FcmGatewayInterface
        {
            public array $sent = [];

            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                $this->sent[] = compact('token', 'title', 'body', 'data');

                return true;
            }
        };
        $this->app->instance(FcmGatewayInterface::class, $gateway);

        ['providerUser' => $providerUser, 'reservation' => $reservation] = $this->pendingReservation();
        Device::create(['user_id' => $providerUser->id, 'fcm_token' => 'provider-device', 'platform' => 'android']);

        $this->confirm($reservation);

        $this->assertCount(1, $gateway->sent);
        $this->assertSame('provider-device', $gateway->sent[0]['token']);
        $this->assertSame('New Booking', $gateway->sent[0]['title']);
        $this->assertSame('provider.booking.confirmed', $gateway->sent[0]['data']['type']);
    }

    public function test_the_new_notifications_are_queued_with_the_shared_retry_policy(): void
    {
        foreach ([NewBookingProviderNotification::class, BookingCancelledProviderNotification::class, ActivitySentBackToReviewNotification::class] as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldQueue::class), "{$class} should be queued.");

            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $this->assertSame(3, $defaults['tries'], "{$class} should retry 3 times.");
            $this->assertSame([30, 120], $defaults['backoff'], "{$class} should back off 30s then 120s.");
        }
    }
}
