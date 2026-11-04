<?php

namespace Tests\Feature\Admin;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsTranslated;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Every in-app notification sent to anyone. Viewing needs notifications.view. DELETING one
 * removes it from that person's feed in the mobile app, so it needs notifications.delete,
 * which only the admin and super admin roles hold.
 */
class NotificationLogTest extends TestCase
{
    use AssertsTranslated;
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function asAdmin(string $role = 'super_admin'): static
    {
        return $this->actingAs($this->makeAdmin($role), 'web');
    }

    // --- Who may use it ----------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool, 2: bool}> [role, may view, may delete] */
    public static function roles(): array
    {
        return [
            'super admin' => ['super_admin', true, true],
            'admin' => ['admin', true, true],
            'support admin (view only)' => ['support_admin', true, false],
            'content admin' => ['content_admin', false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_viewing_and_deleting_need_their_own_permissions(string $role, bool $mayView, bool $mayDelete): void
    {
        [$customerUser] = $this->makeCustomer();
        $entry = $this->giveNotification($customerUser, ['message' => 'Your booking is confirmed']);
        $actor = $this->makeAdmin($role);

        $list = $this->actingAs($actor, 'web')->get(route('admin.notifications.log'));
        $detail = $this->actingAs($actor, 'web')->get(route('admin.notifications.log.show', $entry->id));
        $mayView ? $list->assertOk() : $list->assertForbidden();
        $mayView ? $detail->assertOk() : $detail->assertForbidden();

        $delete = $this->actingAs($actor, 'web')->delete(route('admin.notifications.log.destroy', $entry->id));

        if ($mayDelete) {
            $delete->assertRedirect(route('admin.notifications.log'));
            $this->assertDatabaseMissing('notifications', ['id' => $entry->id]);
        } else {
            $delete->assertForbidden();
            $this->assertDatabaseHas('notifications', ['id' => $entry->id]);
        }
    }

    public function test_the_delete_button_is_shown_only_to_those_who_may_use_it(): void
    {
        [$customerUser] = $this->makeCustomer();
        $this->giveNotification($customerUser);

        $this->asAdmin('admin')->get(route('admin.notifications.log'))->assertSee('value="DELETE"', false);
        $this->asAdmin('support_admin')->get(route('admin.notifications.log'))->assertOk()->assertDontSee('value="DELETE"', false);
    }

    // --- Listing and filtering ------------------------------------------------------

    public function test_it_lists_notifications_of_customers_providers_and_staff(): void
    {
        [$customerUser] = $this->makeCustomer(['name' => 'Layla Customer']);
        [$providerUser] = $this->makeProvider('verified');
        $providerUser->update(['name' => 'Omar Provider']);
        $staff = $this->makeAdmin('admin');
        $staff->update(['name' => 'Sam Staff']);
        $this->giveNotification($customerUser, ['message' => 'For the customer']);
        $this->giveNotification($providerUser, ['message' => 'For the provider']);
        $this->giveNotification($staff, ['message' => 'For the staff member']);

        $this->asAdmin()->get(route('admin.notifications.log'))->assertOk()
            ->assertSee('Layla Customer')->assertSee('Omar Provider')->assertSee('Sam Staff')
            ->assertSee('For the customer')->assertSee('For the provider')->assertSee('For the staff member');
    }

    public function test_it_can_be_filtered_by_the_kind_of_recipient(): void
    {
        [$customerUser] = $this->makeCustomer();
        [$providerUser] = $this->makeProvider('verified');
        $staff = $this->makeAdmin('admin');
        $this->giveNotification($customerUser, ['message' => 'Customer entry']);
        $this->giveNotification($providerUser, ['message' => 'Provider entry']);
        $this->giveNotification($staff, ['message' => 'Staff entry']);
        $viewer = $this->makeAdmin();

        $get = fn (string $kind) => $this->actingAs($viewer, 'web')->get(route('admin.notifications.log', ['recipient' => $kind]));

        $get('customer')->assertSee('Customer entry')->assertDontSee('Provider entry')->assertDontSee('Staff entry');
        $get('provider')->assertSee('Provider entry')->assertDontSee('Customer entry');
        $get('admin')->assertSee('Staff entry')->assertDontSee('Customer entry');
    }

    public function test_it_can_be_filtered_by_type_and_by_read_state(): void
    {
        [$user] = $this->makeCustomer();
        $this->giveNotification($user, ['message' => 'A confirmation'], 'App\\Notifications\\Reservations\\ReservationConfirmedNotification');
        $this->giveNotification($user, ['message' => 'A failed payment'], 'App\\Notifications\\Payments\\PaymentFailedNotification', read: true);
        $viewer = $this->makeAdmin();

        $this->actingAs($viewer, 'web')->get(route('admin.notifications.log', ['type' => 'App\\Notifications\\Payments\\PaymentFailedNotification']))
            ->assertSee('A failed payment')->assertDontSee('A confirmation');
        $this->actingAs($viewer, 'web')->get(route('admin.notifications.log', ['status' => 'read']))
            ->assertSee('A failed payment')->assertDontSee('A confirmation');
        $this->actingAs($viewer, 'web')->get(route('admin.notifications.log', ['status' => 'unread']))
            ->assertSee('A confirmation')->assertDontSee('A failed payment');
    }

    public function test_it_can_be_filtered_by_date(): void
    {
        [$user] = $this->makeCustomer();
        $this->giveNotification($user, ['message' => 'Long ago'], at: Carbon::now()->subDays(30));
        $this->giveNotification($user, ['message' => 'Recent one'], at: Carbon::now()->subDays(2));
        $viewer = $this->makeAdmin();

        $this->actingAs($viewer, 'web')->get(route('admin.notifications.log', ['from' => Carbon::now()->subDays(5)->toDateString()]))
            ->assertSee('Recent one')->assertDontSee('Long ago');
        $this->actingAs($viewer, 'web')->get(route('admin.notifications.log', ['to' => Carbon::now()->subDays(10)->toDateString()]))
            ->assertSee('Long ago')->assertDontSee('Recent one');
        // The end date is the whole day, so an entry made today is found when "to" is today.
        $this->actingAs($viewer, 'web')->get(route('admin.notifications.log', ['to' => Carbon::now()->toDateString()]))
            ->assertSee('Recent one');
    }

    public function test_it_can_be_searched_by_message_name_phone_and_email(): void
    {
        [$layla] = $this->makeCustomer(['name' => 'Layla Hassan', 'phone' => '0533123456', 'email' => 'layla@mail.test']);
        [$karim] = $this->makeCustomer(['name' => 'Karim Nabil', 'phone' => '0599876543', 'email' => 'karim@mail.test']);
        $this->giveNotification($layla, ['message' => 'Dive on Saturday']);
        $this->giveNotification($karim, ['message' => 'Snorkel on Sunday']);
        $viewer = $this->makeAdmin();

        foreach (['Saturday', 'Layla', '0533123456', 'layla@mail'] as $term) {
            $this->actingAs($viewer, 'web')->get(route('admin.notifications.log', ['search' => $term]))
                ->assertSee('Dive on Saturday')->assertDontSee('Snorkel on Sunday');
        }
    }

    public function test_a_nonsense_date_or_kind_is_ignored_and_not_a_server_error(): void
    {
        [$user] = $this->makeCustomer();
        $this->giveNotification($user, ['message' => 'Still listed']);

        $this->asAdmin()->get(route('admin.notifications.log', ['from' => 'not a date', 'to' => '99-99-99', 'recipient' => 'martian', 'status' => 'weird']))
            ->assertOk()->assertSee('Still listed');
    }

    public function test_notifications_of_a_deleted_account_still_show_with_its_name(): void
    {
        [$customerUser] = $this->makeCustomer(['name' => 'Gone Customer']);
        $this->giveNotification($customerUser, ['message' => 'Sent before they left']);
        $customerUser->delete();

        $page = $this->asAdmin()->get(route('admin.notifications.log'));

        $page->assertOk()->assertSee('Gone Customer')->assertSee('Sent before they left')->assertSee(__('admin.accounts.deleted'));
        $this->asAdmin()->get(route('admin.notifications.log', ['recipient' => 'customer']))->assertSee('Gone Customer');
    }

    public function test_an_entry_for_something_that_is_not_a_user_does_not_break_the_page(): void
    {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\X', 'notifiable_type' => 'App\\Models\\Gone', 'notifiable_id' => 999,
            'data' => ['message' => 'Orphan entry'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asAdmin()->get(route('admin.notifications.log'))->assertOk();
    }

    // --- The detail page ----------------------------------------------------------------------

    public function test_the_detail_page_shows_the_recipient_and_the_data_escaped(): void
    {
        [$user] = $this->makeCustomer(['name' => 'Detail Person']);
        $entry = $this->giveNotification($user, ['message' => 'Hello', 'note' => '<img src=x onerror=alert(1)>', 'amount' => 1500]);

        $page = $this->asAdmin()->get(route('admin.notifications.log.show', $entry->id));

        $page->assertOk()->assertSee('Detail Person')->assertSee('1500')
            ->assertDontSee('<img src=x onerror=alert(1)>', false)->assertSee('&lt;img src=x', false);
    }

    public function test_a_made_up_id_is_not_found(): void
    {
        $this->asAdmin()->get(route('admin.notifications.log.show', 'nope'))->assertNotFound();
        $this->asAdmin()->delete(route('admin.notifications.log.destroy', 'nope'))->assertNotFound();
    }

    // --- Deleting ------------------------------------------------------------------------------

    public function test_deleting_removes_it_from_the_customers_feed_in_the_app(): void
    {
        [$user] = $this->makeCustomer();
        $token = $user->createToken('t', ['customer'])->plainTextToken;
        $entry = $this->giveNotification($user, ['message' => 'Delete me']);

        $before = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/customer/notifications')->assertOk();
        $this->assertCount(1, $before->json('data'));

        $this->asAdmin('admin')->delete(route('admin.notifications.log.destroy', $entry->id));

        $this->app['auth']->forgetGuards();
        $after = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/customer/notifications')->assertOk();
        $this->assertCount(0, $after->json('data'));
    }

    // --- Language -------------------------------------------------------------------------------

    public function test_the_log_and_its_detail_page_are_fully_translated_in_both_languages(): void
    {
        [$user] = $this->makeCustomer();
        $entry = $this->giveNotification($user, ['message' => 'Translate me']);
        $admin = $this->makeAdmin();

        $this->inEachAdminLanguage(function (string $locale) use ($admin, $entry) {
            foreach (['log' => route('admin.notifications.log'), 'filtered' => route('admin.notifications.log', ['recipient' => 'customer', 'status' => 'unread']), 'detail' => route('admin.notifications.log.show', $entry->id)] as $name => $url) {
                $this->assertNoRawTranslationKeys($this->actingAs($admin, 'web')->get($url)->assertOk(), "({$name}, {$locale})");
            }
        });
    }
}
