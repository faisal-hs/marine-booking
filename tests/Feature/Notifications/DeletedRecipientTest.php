<?php

namespace Tests\Feature\Notifications;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Reservations\Provider\NewBookingProviderNotification;
use App\Notifications\Reservations\ReservationConfirmedNotification;
use App\Services\Admin\UserManagementService;
use App\Services\NotificationDispatchService;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\Concerns\RelativeDates;
use Tests\TestCase;

/**
 * The admin Users screen soft-deletes accounts, and a soft-deleted user comes back
 * as null through relations such as $activity->provider->user. A notification for a
 * recipient who no longer exists must be skipped quietly: before this was guarded it
 * threw a TypeError out of the listener, so a customer who paid for an activity whose
 * provider account had been deleted got a server error after the reservation was
 * already confirmed.
 */
class DeletedRecipientTest extends TestCase
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

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, $this->futureSaturday(), ['adult' => 1]);

        return compact('providerUser', 'customerUser', 'customer', 'reservation');
    }

    private function deleteAccount(User $user): void
    {
        // Exactly what the admin Users screen does.
        app(UserManagementService::class)->delete($user);
    }

    public function test_dispatching_to_a_recipient_that_no_longer_exists_is_skipped_quietly(): void
    {
        Log::spy();
        ['reservation' => $reservation] = $this->pendingReservation();

        $result = app(NotificationDispatchService::class)->dispatchOnce(
            'test.deleted:1',
            null,
            new NewBookingProviderNotification($reservation)
        );

        $this->assertFalse($result);
        $this->assertDatabaseMissing('notification_dispatch_log', ['event_key' => 'test.deleted:1']);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'test.deleted:1'));
    }

    public function test_a_customer_can_still_pay_when_the_providers_account_was_deleted(): void
    {
        ['providerUser' => $providerUser, 'customerUser' => $customerUser, 'reservation' => $reservation] = $this->pendingReservation();
        $this->deleteAccount($providerUser);

        app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success']);

        $this->assertSame('confirmed', $reservation->fresh()->status->value);
        // The customer is still told; there is simply nobody to tell on the provider side.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $customerUser->id, 'type' => ReservationConfirmedNotification::class]);
        $this->assertSame(0, DB::table('notifications')->where('type', NewBookingProviderNotification::class)->count());
    }

    public function test_cancelling_a_confirmed_booking_works_when_the_providers_account_was_deleted(): void
    {
        ['providerUser' => $providerUser, 'reservation' => $reservation] = $this->pendingReservation();
        app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success']);
        $this->deleteAccount($providerUser);

        app(ReservationService::class)->cancel($reservation->fresh(), 'Changed plans');

        $this->assertSame('cancelled', $reservation->fresh()->status->value);
    }

    public function test_a_reservation_event_does_not_fail_when_the_customers_account_was_deleted(): void
    {
        ['customerUser' => $customerUser, 'reservation' => $reservation] = $this->pendingReservation();
        $this->deleteAccount($customerUser);
        $reservation->update(['hold_expires_at' => now()->subMinute()]);

        app(ReservationService::class)->expireStaleHolds();

        $this->assertSame('expired', $reservation->fresh()->status->value);
    }

    public function test_approving_an_activity_of_a_deleted_provider_is_refused_cleanly(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$providerUser, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'pending_review');
        $this->deleteAccount($providerUser);

        // A deleted account must not get a listing in front of customers. What matters here
        // is HOW it is refused: a normal redirect with a message, not a server error.
        $this->actingAs($this->makeAdmin(), 'web')
            ->from('/admin/activities')
            ->post(route('admin.activities.approve', $activity))
            ->assertRedirect('/admin/activities')
            ->assertSessionHasErrors('provider');

        $this->assertSame('pending_review', $activity->fresh()->status);
    }

    public function test_rejecting_an_activity_of_a_deleted_provider_does_not_crash_the_notification(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$providerUser, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'pending_review');
        $this->deleteAccount($providerUser);

        // Rejecting is still allowed, and tells the provider: there is nobody left to tell.
        // A 500 would still have saved the new status first, so the response is what is checked.
        $this->actingAs($this->makeAdmin(), 'web')
            ->post(route('admin.activities.reject', $activity), ['rejection_reason' => 'Photos too dark'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $activity->fresh()->status);
    }
}
