<?php

namespace Tests\Feature\Account;

use App\Models\Activity;
use App\Models\Broadcast;
use App\Notifications\Activities\ActivitySuspendedNotification;
use App\Notifications\Broadcasts\BroadcastNotification;
use App\Notifications\Payments\PaymentFailedNotification;
use App\Notifications\Providers\ProviderRejectedNotification;
use App\Notifications\Providers\ProviderVerifiedNotification;
use App\Notifications\Reservations\Provider\NewBookingProviderNotification;
use App\Notifications\Reservations\ReservationConfirmedNotification;
use App\Services\BroadcastService;
use App\Services\NotificationFeed;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * What changes around the rest of the system once one person can hold a customer account and a
 * provider account at the same time.
 */
class DualAccountEffectsTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function audience(string $audience): array
    {
        $broadcast = new Broadcast(['title' => 'T', 'body' => 'B', 'audience' => $audience]);

        return app(BroadcastService::class)->recipients($broadcast)->pluck('id')->all();
    }

    // --- Announcements follow accounts, not the type someone registered as -------------------

    public function test_a_person_with_both_accounts_is_reached_by_both_kinds_of_announcement(): void
    {
        [$both] = $this->makeDualAccount('verified');

        $this->assertContains($both->id, $this->audience('customers'));
        $this->assertContains($both->id, $this->audience('providers'));
    }

    public function test_a_provider_who_never_used_the_customer_side_is_not_in_the_customer_audience(): void
    {
        [$providerOnly] = $this->makeProvider('verified');

        $this->assertNotContains($providerOnly->id, $this->audience('customers'));
        $this->assertContains($providerOnly->id, $this->audience('providers'));

        // The moment they use the customer side they are customers too.
        $this->asAccount($this->tokenFor($providerOnly, 'provider'))->postJson('/api/v1/account/switch', ['account_type' => 'customer'])->assertOk();
        $this->assertContains($providerOnly->id, $this->audience('customers'));
    }

    public function test_the_provider_audience_is_approved_providers_only(): void
    {
        [$waitingDual] = $this->makeDualAccount('pending');
        [$waitingProvider] = $this->makeProvider('pending');
        [$rejected] = $this->makeProvider('rejected');

        $providers = $this->audience('providers');

        foreach ([$waitingDual, $waitingProvider, $rejected] as $unapproved) {
            $this->assertNotContains($unapproved->id, $providers);
        }
        $this->assertContains($waitingDual->id, $this->audience('customers'), 'They are still customers.');
        $this->assertNotContains($waitingProvider->id, $this->audience('customers'));
    }

    public function test_one_named_person_can_be_a_provider_only_account_and_staff_never_are(): void
    {
        [$providerOnly] = $this->makeProvider('verified');
        $providerOnly->update(['phone' => '0577000001']);
        $staff = $this->makeAdmin('admin');
        $staff->update(['phone' => '0577000002']);
        [$nobody] = $this->makeCustomer(['phone' => '0577000003']);
        $nobody->customer->delete();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.store'), ['title' => 'Hi', 'body' => 'There', 'audience' => 'user', 'recipient' => '0577000001'])
            ->assertSessionHasNoErrors();
        foreach (['0577000002', '0577000003'] as $refused) {
            $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.store'), ['title' => 'Hi', 'body' => 'There', 'audience' => 'user', 'recipient' => $refused])
                ->assertSessionHasErrors('recipient');
        }
        $this->assertSame(1, Broadcast::count());
    }

    // --- Suspending or deleting a person affects both accounts ----------------------------------

    public function test_suspending_from_the_customer_screen_also_takes_the_providers_listings_off_the_site(): void
    {
        [$user, $customer, $provider] = $this->makeDualAccount('verified');
        $activity = $this->makeActivity($provider, 'published');
        $token = $this->tokenFor($user, 'provider');

        $this->actingAs($this->makeAdmin(), 'web')->post(route('admin.customers.suspend', $customer))->assertSessionHasNoErrors();

        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame('inactive', $activity->fresh()->status);
        $this->assertSame(0, $user->tokens()->count());
        $this->asAccount($token)->getJson('/api/v1/provider/profile')->assertUnauthorized();
    }

    public function test_deleting_from_the_customer_screen_is_blocked_by_bookings_on_the_provider_side(): void
    {
        [$user, $customer, $provider] = $this->makeDualAccount('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $other] = $this->makeCustomer();
        $this->payFor($this->bookActivity($other, $activity, $option));

        $this->actingAs($this->makeAdmin(), 'web')->delete(route('admin.customers.destroy', $customer))->assertSessionHas('error');

        $this->assertFalse($user->fresh()->trashed(), 'The person must be untouched.');
        $this->assertSame('published', $activity->fresh()->status);
    }

    public function test_deleting_from_the_provider_screen_is_blocked_by_bookings_on_the_customer_side(): void
    {
        [$user, $customer, $provider] = $this->makeDualAccount('verified');
        [$otherUser,, $otherProvider] = [null, null, $this->makeProvider('verified')[1]];
        [$activity, $option] = $this->makeBookableActivity($otherProvider);
        $this->payFor($this->bookActivity($customer, $activity, $option));

        $this->actingAs($this->makeAdmin(), 'web')->delete(route('admin.providers.destroy', $provider))->assertSessionHas('error');

        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_deleting_a_person_with_no_live_bookings_removes_both_accounts_and_the_listings(): void
    {
        [$user, $customer, $provider] = $this->makeDualAccount('verified');
        $activity = $this->makeActivity($provider, 'published');

        $this->actingAs($this->makeAdmin(), 'web')->delete(route('admin.customers.destroy', $customer))->assertSessionMissing('error');

        $this->assertTrue($user->fresh()->trashed());
        $this->assertSame('inactive', $activity->fresh()->status);
        // Both sign-ins say the same thing, clearly.
        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => $user->phone])->assertStatus(422);
        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => $user->phone])->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $user->phone])->assertStatus(422);
    }

    // --- Not your own activity -------------------------------------------------------------------

    private function bookingBody(Activity $activity): array
    {
        return [
            'activity' => $activity->uuid,
            'booking_option' => $activity->bookingOptions()->firstOrFail()->uuid,
            'reservation_date' => Carbon::now()->startOfDay()->next(Carbon::SATURDAY)->toDateString(),
            'participants' => ['adult' => 1],
        ];
    }

    public function test_a_provider_cannot_book_their_own_activity_as_a_customer(): void
    {
        [$user,, $provider] = $this->makeDualAccount('verified');
        [$own] = $this->makeBookableActivity($provider);

        $this->asAccount($this->tokenFor($user, 'customer'))->postJson('/api/v1/customer/reservations', $this->bookingBody($own))
            ->assertStatus(422)->assertJsonValidationErrors('activity');

        $this->assertSame(0, $own->reservations()->count());
    }

    public function test_the_same_person_can_book_someone_elses_activity(): void
    {
        [$user] = $this->makeDualAccount('verified');
        [, $otherProvider] = $this->makeProvider('verified');
        [$theirs] = $this->makeBookableActivity($otherProvider);

        $this->asAccount($this->tokenFor($user, 'customer'))->postJson('/api/v1/customer/reservations', $this->bookingBody($theirs))->assertCreated();
    }

    // --- Who owns an activity ---------------------------------------------------------------------

    public function test_an_approved_provider_who_registered_as_a_customer_manages_their_own_activities_only(): void
    {
        [$user,, $provider] = $this->makeDualAccount('verified');
        $mine = $this->makeActivity($provider, 'draft', ['title' => 'Mine']);
        [, $otherProvider] = $this->makeProvider('verified');
        $theirs = $this->makeActivity($otherProvider, 'draft', ['title' => 'Theirs']);
        $token = $this->tokenFor($user, 'provider');

        $this->asAccount($token)->putJson("/api/v1/provider/activities/{$mine->uuid}", ['title' => 'Mine, renamed'])->assertOk();
        $this->asAccount($token)->putJson("/api/v1/provider/activities/{$theirs->uuid}", ['title' => 'Hijacked'])->assertForbidden();

        $this->assertSame('Mine, renamed', $mine->fresh()->title);
        $this->assertSame('Theirs', $theirs->fresh()->title);
    }

    // --- Each account has its own notification feed -------------------------------------------------

    private function feedFor(string $token, string $account): array
    {
        return collect($this->asAccount($token)->getJson("/api/v1/{$account}/notifications")->assertOk()->json('data'))->pluck('type')->all();
    }

    private function seedFeedFor($user): void
    {
        $this->giveNotification($user, ['type' => 'customer.thing', 'message' => 'Your reservation is confirmed'], ReservationConfirmedNotification::class);
        $this->giveNotification($user, ['type' => 'customer.pay', 'message' => 'Payment failed'], PaymentFailedNotification::class);
        $this->giveNotification($user, ['type' => 'provider.thing', 'message' => 'New booking received'], NewBookingProviderNotification::class);
        $this->giveNotification($user, ['type' => 'provider.act', 'message' => 'Activity suspended'], ActivitySuspendedNotification::class);
        $this->giveNotification($user, ['type' => 'broadcast', 'message' => 'Winter schedule'], BroadcastNotification::class);
        $this->giveNotification($user, ['type' => 'provider.verified', 'message' => 'You are a provider now'], ProviderVerifiedNotification::class);
    }

    public function test_the_customer_feed_leaves_out_provider_only_notifications(): void
    {
        [$user] = $this->makeDualAccount('verified');
        $this->seedFeedFor($user);

        $types = $this->feedFor($this->tokenFor($user, 'customer'), 'customer');

        $this->assertEqualsCanonicalizing(['customer.thing', 'customer.pay', 'broadcast', 'provider.verified'], $types);
    }

    public function test_the_provider_feed_leaves_out_customer_only_notifications(): void
    {
        [$user] = $this->makeDualAccount('verified');
        $this->seedFeedFor($user);

        $types = $this->feedFor($this->tokenFor($user, 'provider'), 'provider');

        $this->assertEqualsCanonicalizing(['provider.thing', 'provider.act', 'broadcast', 'provider.verified'], $types);
    }

    public function test_the_outcome_of_a_provider_request_shows_on_the_customer_side_and_after_approval_on_both(): void
    {
        // A customer who asked to become a provider must see the answer while they are still on the
        // customer side. (An unapproved provider account cannot read notifications at all.)
        [$rejected] = $this->makeDualAccount('rejected');
        $this->giveNotification($rejected, ['type' => 'provider.rejected', 'message' => 'Not approved. Reason: unclear'], ProviderRejectedNotification::class);
        $this->assertContains('provider.rejected', $this->feedFor($this->tokenFor($rejected, 'customer'), 'customer'));
        $this->asAccount($this->tokenFor($rejected, 'provider'))->getJson('/api/v1/provider/notifications')->assertForbidden();

        [$approved] = $this->makeDualAccount('verified');
        $this->giveNotification($approved, ['type' => 'provider.verified', 'message' => 'You are a provider now'], ProviderVerifiedNotification::class);
        $this->assertContains('provider.verified', $this->feedFor($this->tokenFor($approved, 'customer'), 'customer'));
        $this->assertContains('provider.verified', $this->feedFor($this->tokenFor($approved, 'provider'), 'provider'));
    }

    public function test_the_unread_count_and_mark_all_read_belong_to_the_account_in_use(): void
    {
        [$user] = $this->makeDualAccount('verified');
        $this->seedFeedFor($user);
        $customer = $this->tokenFor($user, 'customer');
        $provider = $this->tokenFor($user, 'provider');

        $this->assertSame(4, $this->asAccount($customer)->getJson('/api/v1/customer/notifications/unread-count')->json('data.count'));
        $this->assertSame(4, $this->asAccount($provider)->getJson('/api/v1/provider/notifications/unread-count')->json('data.count'));

        $this->asAccount($customer)->postJson('/api/v1/customer/notifications/read-all')->assertOk();

        $this->assertSame(0, $this->asAccount($customer)->getJson('/api/v1/customer/notifications/unread-count')->json('data.count'));
        // The provider-only ones were not touched; the shared ones were read.
        $this->assertSame(2, $this->asAccount($provider)->getJson('/api/v1/provider/notifications/unread-count')->json('data.count'));
    }

    public function test_a_notification_nobody_classified_shows_in_both_feeds_rather_than_vanishing(): void
    {
        [$user] = $this->makeDualAccount('verified');
        $this->giveNotification($user, ['type' => 'new.thing', 'message' => 'From a class added later'], 'App\\Notifications\\SomethingNew\\BrandNewNotification');

        $this->assertContains('new.thing', $this->feedFor($this->tokenFor($user, 'customer'), 'customer'));
        $this->assertContains('new.thing', $this->feedFor($this->tokenFor($user, 'provider'), 'provider'));
    }

    public function test_a_person_with_one_account_sees_all_of_their_own_notifications(): void
    {
        [$customer] = $this->makeCustomer();
        [$provider] = $this->makeProvider('verified');
        $this->giveNotification($customer, ['type' => 'customer.thing', 'message' => 'Confirmed'], ReservationConfirmedNotification::class);
        $this->giveNotification($provider, ['type' => 'provider.thing', 'message' => 'New booking'], NewBookingProviderNotification::class);

        $this->assertSame(['customer.thing'], $this->feedFor($this->tokenFor($customer, 'customer'), 'customer'));
        $this->assertSame(['provider.thing'], $this->feedFor($this->tokenFor($provider, 'provider'), 'provider'));
    }

    /**
     * Every notification class has to be decided: for customers, for providers, for staff, or for both.
     * Unclassified ones show in both feeds (the safe failure), but that would be a mistake nobody
     * noticed, so a NEW class fails here until someone places it.
     */
    public function test_every_notification_class_is_classified(): void
    {
        $sharedByDesign = [BroadcastNotification::class, ProviderVerifiedNotification::class, ProviderRejectedNotification::class];
        $classified = array_merge(NotificationFeed::CUSTOMER_ONLY, NotificationFeed::PROVIDER_ONLY, $sharedByDesign);
        $unclassified = [];
        $base = app_path('Notifications');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace([$base . DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR], ['', '', '\\'], $file->getPathname());
            $class = 'App\\Notifications\\' . $relative;

            // Staff notifications, channels and shared helpers are not part of either account's feed.
            if (str_contains($relative, '\\Admin\\') || str_starts_with($relative, 'Channels\\') || str_starts_with($relative, 'Concerns\\') || str_starts_with(class_basename($class), 'Base')) {
                continue;
            }
            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }
            if (! in_array($class, $classified, true)) {
                $unclassified[] = $class;
            }
        }

        $this->assertSame([], $unclassified, "These notification classes are not placed in NotificationFeed (customer, provider) or the shared list:\n - " . implode("\n - ", $unclassified));
    }
}
