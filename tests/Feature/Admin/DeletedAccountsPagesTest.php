<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;
use App\Notifications\Activities\ActivityApprovedNotification;
use App\Services\Admin\UserManagementService;
use App\Services\NotificationDispatchService;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\Concerns\RelativeDates;
use Tests\TestCase;

/**
 * Deleting an account soft-deletes the user, and a soft-deleted user used to come back
 * as null through Provider::user() and Customer::user(). Every admin page that showed a
 * provider or customer name then failed with a server error: after deleting one provider
 * and one customer, the dashboard, the providers, reservations and payments pages all
 * returned 500, and the public reviews endpoint broke for everyone.
 *
 * The history is still real, so it must keep showing, marked as belonging to a deleted
 * account.
 */
class DeletedAccountsPagesTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;
    use RelativeDates;

    /** @return array{provider: Provider, activity: Activity, reservation: Reservation, payment: Payment, review: Review, admin: User} */
    private function worldWithDeletedAccounts(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$providerUser, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published', ['title' => 'Deleted World Dive']);
        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-'.uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create(['name' => 'Layla Hassan']);
        $customer = Customer::create(['user_id' => $customerUser->id]);

        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, $this->futureSaturday(), ['adult' => 1]);
        $payment = app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success'])['payment'];
        $review = Review::create(['customer_id' => $customer->id, 'activity_id' => $activity->id, 'reservation_id' => $reservation->id, 'rating' => 5, 'comment' => 'Wonderful']);

        // Exactly what the admin screens do when you delete an account.
        $service = app(UserManagementService::class);
        $service->delete($providerUser);
        $service->delete($customerUser);

        return ['provider' => $provider, 'activity' => $activity, 'reservation' => $reservation, 'payment' => $payment, 'review' => $review, 'admin' => $this->makeAdmin()];
    }

    public function test_every_admin_page_still_loads_when_a_provider_and_a_customer_were_deleted(): void
    {
        $w = $this->worldWithDeletedAccounts();

        $pages = [
            'dashboard' => route('admin.dashboard'),
            'providers' => route('admin.providers.index'),
            'provider' => route('admin.providers.show', $w['provider']),
            'activities' => route('admin.activities.index'),
            'activity' => route('admin.activities.show', $w['activity']),
            'reservations' => route('admin.reservations.index'),
            'reservation' => route('admin.reservations.show', $w['reservation']),
            'payments' => route('admin.payments.index'),
            'payment' => route('admin.payments.show', $w['payment']),
            'reviews' => route('admin.reviews.index'),
            'review' => route('admin.reviews.show', $w['review']),
            'reports' => route('admin.reports.index'),
        ];

        foreach ($pages as $name => $url) {
            $this->actingAs($w['admin'], 'web')->get($url)->assertOk("The {$name} page failed for deleted accounts.");
        }
    }

    public function test_the_admin_api_and_the_public_reviews_endpoint_still_work(): void
    {
        $w = $this->worldWithDeletedAccounts();

        foreach (['reservations', 'payments', 'reviews'] as $resource) {
            $this->actingAs($w['admin'], 'web')->getJson("/api/v1/admin/{$resource}")->assertOk();
        }

        // Public: one deleted customer's review must not break the page for everyone.
        $this->getJson("/api/v1/public/activities/{$w['activity']->slug}/reviews")->assertOk();
    }

    public function test_history_keeps_the_name_and_marks_it_as_deleted(): void
    {
        $w = $this->worldWithDeletedAccounts();

        $this->actingAs($w['admin'], 'web')->get(route('admin.reservations.show', $w['reservation']))
            ->assertSee('Layla Hassan')
            ->assertSee(__('admin.accounts.deleted'));

        $this->actingAs($w['admin'], 'web')->get(route('admin.providers.show', $w['provider']))
            ->assertSee(__('admin.accounts.deleted'));
    }

    public function test_an_active_account_is_not_marked_as_deleted(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [, $provider] = $this->makeProvider('verified');

        $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.providers.show', $provider))
            ->assertOk()
            ->assertDontSee(__('admin.accounts.deleted'));
    }

    public function test_nothing_is_sent_to_a_deleted_account_even_though_the_relation_still_returns_it(): void
    {
        $w = $this->worldWithDeletedAccounts();
        $trashed = $w['provider']->user;

        $this->assertTrue($trashed->trashed(), 'The relation returns the deleted account so screens can name it.');

        // The provider legitimately got a booking alert BEFORE the account was deleted, so
        // the test is that nothing NEW arrives afterwards.
        $before = DB::table('notifications')->where('notifiable_id', $trashed->id)->count();

        $sent = app(NotificationDispatchService::class)->dispatchOnce(
            'test.trashed:1',
            $trashed,
            new ActivityApprovedNotification($w['activity'])
        );

        $this->assertFalse($sent);
        $this->assertDatabaseMissing('notification_dispatch_log', ['event_key' => 'test.trashed:1']);
        $this->assertSame($before, DB::table('notifications')->where('notifiable_id', $trashed->id)->count());
    }
}
