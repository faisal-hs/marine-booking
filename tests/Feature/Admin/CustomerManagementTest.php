<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\Favorite;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\CustomerManagementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsTranslated;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The Customers module. Before it, a customer could only be reached through the generic
 * Users form. These tests cover the list, create, edit, suspend, delete and restore,
 * the permission each needs, and the two things that make deleting a customer safe:
 * a live booking blocks it, and the booking history survives it.
 */
class CustomerManagementTest extends TestCase
{
    use AssertsTranslated;
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Layla Hassan',
            'phone' => '0533000111',
            'email' => 'layla@example.test',
            'date_of_birth' => '1992-04-18',
            'preferred_language' => 'ar',
        ], $overrides);
    }

    private function asAdmin(string $role = 'super_admin'): static
    {
        return $this->actingAs($this->makeAdmin($role), 'web');
    }

    // --- Who may do what ---------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function permissionMatrix(): array
    {
        $actions = ['index', 'show', 'create', 'store', 'edit', 'update', 'suspend', 'reactivate', 'destroy', 'restore'];
        $allowed = [
            'super_admin' => $actions,
            'admin' => $actions,
            'support_admin' => ['index', 'show'],
            'content_admin' => [],
            'none' => [],
        ];
        $cases = [];

        foreach ($allowed as $role => $may) {
            foreach ($actions as $action) {
                $cases["{$role} / {$action}"] = [$role, $action, in_array($action, $may, true) ? 'ok' : 'denied'];
            }
        }

        return $cases;
    }

    #[DataProvider('permissionMatrix')]
    public function test_each_action_needs_its_permission(string $role, string $action, string $expected): void
    {
        $actor = $this->makeAdmin('super_admin');
        $actor->syncRoles($role === 'none' ? [] : [$role]);
        [$user, $customer] = $this->makeCustomer();

        if ($action === 'restore') {
            $user->delete();
        }
        if ($action === 'reactivate') {
            $user->update(['status' => 'suspended']);
        }

        $client = $this->actingAs($actor, 'web');
        $response = match ($action) {
            'index' => $client->get(route('admin.customers.index')),
            'show' => $client->get(route('admin.customers.show', $customer)),
            'create' => $client->get(route('admin.customers.create')),
            'store' => $client->post(route('admin.customers.store'), $this->validPayload()),
            'edit' => $client->get(route('admin.customers.edit', $customer)),
            'update' => $client->put(route('admin.customers.update', $customer), $this->validPayload(['phone' => '0533000222', 'email' => 'new@x.test'])),
            'suspend' => $client->post(route('admin.customers.suspend', $customer)),
            'reactivate' => $client->post(route('admin.customers.reactivate', $customer)),
            'destroy' => $client->delete(route('admin.customers.destroy', $customer)),
            'restore' => $client->post(route('admin.customers.restore', $customer)),
        };

        if ($expected === 'denied') {
            $this->assertContains($response->getStatusCode(), [302, 403], "{$role} must not be able to {$action}");
            if ($role !== 'none') {
                $response->assertForbidden();
            }
        } else {
            $this->assertContains($response->getStatusCode(), [200, 302], "{$role} should be able to {$action}");
            $this->assertNotSame(403, $response->getStatusCode());
        }
    }

    public function test_a_denied_change_really_changes_nothing(): void
    {
        [$user, $customer] = $this->makeCustomer(['name' => 'Untouched Person']);
        $support = $this->makeAdmin('support_admin');

        $this->actingAs($support, 'web')->put(route('admin.customers.update', $customer), $this->validPayload())->assertForbidden();
        $this->actingAs($support, 'web')->post(route('admin.customers.suspend', $customer))->assertForbidden();
        $this->actingAs($support, 'web')->delete(route('admin.customers.destroy', $customer))->assertForbidden();

        $user->refresh();
        $this->assertSame('Untouched Person', $user->name);
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->trashed());
    }

    // --- The list ----------------------------------------------------------------

    public function test_the_list_can_be_searched_by_name_phone_and_email(): void
    {
        $this->makeCustomer(['name' => 'Karim Nabil', 'phone' => '0501110001', 'email' => 'karim@mail.test']);
        $this->makeCustomer(['name' => 'Sara Adel', 'phone' => '0502220002', 'email' => 'sara@mail.test']);

        foreach (['Karim', '0501110001', 'karim@mail'] as $term) {
            $this->asAdmin()->get(route('admin.customers.index', ['search' => $term]))
                ->assertOk()->assertSee('Karim Nabil')->assertDontSee('Sara Adel');
        }
    }

    public function test_the_list_can_be_filtered_by_account_state_and_hides_deleted_accounts_by_default(): void
    {
        $this->makeCustomer(['name' => 'Active Customer']);
        [$suspended] = $this->makeCustomer(['name' => 'Suspended Customer']);
        $suspended->update(['status' => 'suspended']);
        [$deleted] = $this->makeCustomer(['name' => 'Deleted Customer']);
        $deleted->delete();

        $admin = $this->makeAdmin();
        $get = fn (array $q) => $this->actingAs($admin, 'web')->get(route('admin.customers.index', $q));

        $get([])->assertSee('Active Customer')->assertSee('Suspended Customer')->assertDontSee('Deleted Customer');
        $get(['account' => 'active'])->assertSee('Active Customer')->assertDontSee('Suspended Customer');
        $get(['account' => 'suspended'])->assertSee('Suspended Customer')->assertDontSee('Active Customer');
        $get(['account' => 'deleted'])->assertSee('Deleted Customer')->assertDontSee('Active Customer');
    }

    public function test_the_list_shows_how_many_bookings_each_customer_has(): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $busy] = $this->makeCustomer(['name' => 'Busy Customer']);
        $this->bookActivity($busy, $activity, $option);

        $rows = app(CustomerRepositoryInterface::class)->paginateForAdmin([])->getCollection()->keyBy('id');

        $this->assertSame(1, $rows[$busy->id]->reservations_count);
    }

    // --- Creating ----------------------------------------------------------------

    public function test_an_admin_can_create_a_customer(): void
    {
        $response = $this->asAdmin()->post(route('admin.customers.store'), $this->validPayload());

        $customer = Customer::whereHas('user', fn ($q) => $q->where('phone', '0533000111'))->firstOrFail();
        $response->assertRedirect(route('admin.customers.show', $customer));

        $this->assertSame('customer', $customer->user->user_type);
        $this->assertSame('active', $customer->user->status);
        $this->assertNull($customer->user->password);
        $this->assertSame('ar', $customer->preferred_language);
        $this->assertSame('1992-04-18', $customer->date_of_birth->toDateString());
    }

    public function test_the_language_defaults_to_english(): void
    {
        $this->asAdmin()->post(route('admin.customers.store'), $this->validPayload(['preferred_language' => '']));

        $this->assertSame('en', Customer::firstOrFail()->preferred_language);
    }

    public function test_the_new_customer_can_sign_in_with_the_phone_the_admin_entered(): void
    {
        $this->asAdmin()->post(route('admin.customers.store'), $this->validPayload(['phone' => '0533000999']));
        $customer = Customer::firstOrFail();

        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0533000999'])->assertSuccessful();
        $login = $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '0533000999', 'code' => config('otp.default_code')]);

        $login->assertSuccessful();
        $this->assertNotEmpty($login->json('data.token'));
        $this->assertSame(1, Customer::count(), 'Signing in must reach the account the admin created, not make a second one.');
        $this->assertSame($customer->user_id, User::where('phone', '0533000999')->value('id'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPayloads(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'no phone' => [['phone' => ''], 'phone'],
            'a bad email' => [['email' => 'nope'], 'email'],
            'a birth date in the future' => [['date_of_birth' => '2999-01-01'], 'date_of_birth'],
            'a birth date that is not a date' => [['date_of_birth' => 'last tuesday'], 'date_of_birth'],
            'an unsupported language' => [['preferred_language' => 'fr'], 'preferred_language'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected_and_nothing_is_created(array $overrides, string $field): void
    {
        $this->asAdmin()->post(route('admin.customers.store'), $this->validPayload($overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, Customer::count());
    }

    public function test_a_phone_or_email_already_in_use_is_refused_even_by_a_deleted_account(): void
    {
        [$existing] = $this->makeCustomer(['phone' => '0533111222', 'email' => 'taken@x.test']);
        $existing->delete();

        $this->asAdmin()->post(route('admin.customers.store'), $this->validPayload(['phone' => '0533111222']))->assertSessionHasErrors('phone');
        $this->asAdmin()->post(route('admin.customers.store'), $this->validPayload(['email' => 'taken@x.test']))->assertSessionHasErrors('email');
    }

    // --- Editing -----------------------------------------------------------------

    public function test_an_admin_can_edit_a_customer(): void
    {
        [, $customer] = $this->makeCustomer();

        $this->asAdmin()->put(route('admin.customers.update', $customer), $this->validPayload([
            'name' => 'Renamed Customer', 'phone' => '0533777888', 'preferred_language' => 'en', 'date_of_birth' => '1980-01-02',
        ]))->assertRedirect(route('admin.customers.show', $customer));

        $customer->refresh();
        $this->assertSame('Renamed Customer', $customer->user->name);
        $this->assertSame('0533777888', $customer->user->phone);
        $this->assertSame('en', $customer->preferred_language);
        $this->assertSame('1980-01-02', $customer->date_of_birth->toDateString());
    }

    public function test_editing_cannot_change_the_account_state(): void
    {
        [$user, $customer] = $this->makeCustomer();

        $this->asAdmin()->put(route('admin.customers.update', $customer), $this->validPayload(['phone' => '0533333444', 'status' => 'suspended']))
            ->assertSessionHasNoErrors();

        $this->assertSame('active', $user->fresh()->status, 'Suspending has its own action, which also signs the customer out.');
    }

    public function test_the_customers_own_phone_and_email_are_not_a_conflict_when_saving(): void
    {
        [$user, $customer] = $this->makeCustomer(['phone' => '0533909090', 'email' => 'mine@x.test']);

        $this->asAdmin()->put(route('admin.customers.update', $customer), $this->validPayload(['phone' => '0533909090', 'email' => 'mine@x.test']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_email_and_birth_date_can_be_cleared_and_an_empty_language_keeps_the_old_one(): void
    {
        [$user, $customer] = $this->makeCustomer(['email' => 'old@x.test']);
        $customer->update(['date_of_birth' => '1990-05-05', 'preferred_language' => 'ar']);

        $this->asAdmin()->put(route('admin.customers.update', $customer), $this->validPayload(['email' => '', 'date_of_birth' => '', 'preferred_language' => '']))
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertNull($customer->user->email);
        $this->assertNull($customer->date_of_birth);
        $this->assertSame('ar', $customer->preferred_language);
    }

    // --- Suspending ----------------------------------------------------------------

    public function test_suspending_signs_the_customer_out_at_once_and_blocks_signing_back_in(): void
    {
        [$user, $customer] = $this->makeCustomer(['phone' => '0533444555']);
        $token = $user->createToken('t', ['customer'])->plainTextToken;

        $this->asAdmin()->post(route('admin.customers.suspend', $customer))->assertSessionHasNoErrors();

        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/customer/profile')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0533444555'])->assertSuccessful();
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '0533444555', 'code' => config('otp.default_code')])->assertStatus(422);
    }

    public function test_a_customer_can_be_reactivated_and_suspending_twice_is_harmless(): void
    {
        [$user, $customer] = $this->makeCustomer();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.customers.suspend', $customer));
        $this->actingAs($admin, 'web')->post(route('admin.customers.suspend', $customer))->assertSessionHasNoErrors();
        $this->assertSame('suspended', $user->fresh()->status);

        $this->actingAs($admin, 'web')->post(route('admin.customers.reactivate', $customer))->assertSessionHasNoErrors();
        $this->assertSame('active', $user->fresh()->status);
    }

    // --- Deleting and restoring --------------------------------------------------------

    public function test_deleting_soft_deletes_the_account_and_keeps_the_customer_row_and_history(): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [$user, $customer] = $this->makeCustomer();
        $reservation = $this->bookActivity($customer, $activity, $option);
        $this->payFor($reservation);
        Reservation::whereKey($reservation->id)->update(['status' => 'completed']);
        $review = Review::create(['customer_id' => $customer->id, 'activity_id' => $activity->id, 'reservation_id' => $reservation->id, 'rating' => 5, 'comment' => 'Great']);
        Favorite::create(['customer_id' => $customer->id, 'activity_id' => $activity->id]);
        $user->createToken('t', ['customer']);

        $this->asAdmin()->delete(route('admin.customers.destroy', $customer))->assertRedirect(route('admin.customers.index'));

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSame(0, $user->tokens()->count());
        // The customer row is what the bookings, reviews and favorites hang from (ON DELETE CASCADE).
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
        $this->assertDatabaseHas('payments', ['reservation_id' => $reservation->id]);
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('favorites', ['customer_id' => $customer->id]);
    }

    public function test_a_deleted_customer_leaves_the_default_list_but_stays_reachable_and_can_be_restored(): void
    {
        [$user, $customer] = $this->makeCustomer(['name' => 'Gone Person']);
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->delete(route('admin.customers.destroy', $customer));

        $this->actingAs($admin, 'web')->get(route('admin.customers.index'))->assertDontSee('Gone Person');
        $this->actingAs($admin, 'web')->get(route('admin.customers.show', $customer))->assertOk()->assertSee(__('admin.accounts.deleted'));

        $this->actingAs($admin, 'web')->post(route('admin.customers.restore', $customer))->assertRedirect(route('admin.customers.show', $customer));

        $this->assertFalse($user->fresh()->trashed());
        $this->actingAs($admin, 'web')->get(route('admin.customers.index'))->assertSee('Gone Person');
    }

    public function test_deleting_twice_or_restoring_a_live_customer_is_harmless(): void
    {
        [, $customer] = $this->makeCustomer();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.customers.restore', $customer))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'web')->delete(route('admin.customers.destroy', $customer))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'web')->delete(route('admin.customers.destroy', $customer))->assertSessionHasNoErrors();
    }

    /** @return array<string, array{0: string}> */
    public static function liveBookingStates(): array
    {
        return [
            'a confirmed booking still ahead' => ['confirmed_future'],
            'a confirmed booking for today' => ['confirmed_today'],
            'a pending booking whose hold is still running' => ['pending_hold'],
        ];
    }

    #[DataProvider('liveBookingStates')]
    public function test_a_live_booking_blocks_the_delete_and_explains_why(string $state): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [$user, $customer] = $this->makeCustomer();
        $reservation = $this->bookActivity($customer, $activity, $option, $state === 'confirmed_today' ? Carbon::today() : null);
        if ($state !== 'pending_hold') {
            $this->payFor($reservation);
        }

        $this->asAdmin()->delete(route('admin.customers.destroy', $customer))->assertSessionHas('error');

        $this->assertStringContainsString('1 upcoming booking', session('error'));
        $this->assertFalse($user->fresh()->trashed(), 'The customer must be untouched.');
    }

    /** @return array<string, array{0: string}> */
    public static function historyStates(): array
    {
        return [
            'a cancelled booking' => ['cancelled'],
            'an expired hold' => ['expired'],
            'a completed booking' => ['completed'],
            'a confirmed booking that already took place' => ['confirmed_past'],
            'a pending booking whose hold ran out' => ['pending_lapsed'],
        ];
    }

    #[DataProvider('historyStates')]
    public function test_bookings_that_are_history_do_not_block_the_delete(string $state): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [$user, $customer] = $this->makeCustomer();
        $reservation = $this->bookActivity($customer, $activity, $option);
        $this->payFor($reservation);

        match ($state) {
            'cancelled' => Reservation::whereKey($reservation->id)->update(['status' => 'cancelled']),
            'expired' => Reservation::whereKey($reservation->id)->update(['status' => 'expired']),
            'completed' => Reservation::whereKey($reservation->id)->update(['status' => 'completed']),
            'confirmed_past' => Reservation::whereKey($reservation->id)->update(['reservation_date' => Carbon::yesterday()->toDateString()]),
            'pending_lapsed' => Reservation::whereKey($reservation->id)->update(['status' => 'pending', 'hold_expires_at' => now()->subMinute()]),
        };

        $this->asAdmin()->delete(route('admin.customers.destroy', $customer))->assertSessionMissing('error');

        $this->assertTrue($user->fresh()->trashed(), "{$state} must not block deleting the customer.");
    }

    public function test_only_the_customers_own_bookings_count_towards_the_block(): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $other] = $this->makeCustomer();
        $this->payFor($this->bookActivity($other, $activity, $option));
        [$user, $quiet] = $this->makeCustomer();

        $this->asAdmin()->delete(route('admin.customers.destroy', $quiet))->assertSessionMissing('error');

        $this->assertTrue($user->fresh()->trashed());
    }

    // --- The detail page ------------------------------------------------------------------

    public function test_the_detail_page_shows_bookings_spending_reviews_and_favorites(): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider, 'published', ['title' => 'Coral Reef Dive']);
        [, $customer] = $this->makeCustomer();
        $reservation = $this->bookActivity($customer, $activity, $option);
        $this->payFor($reservation);
        Review::create(['customer_id' => $customer->id, 'activity_id' => $activity->id, 'reservation_id' => $reservation->id, 'rating' => 4, 'comment' => 'Nice']);
        Favorite::create(['customer_id' => $customer->id, 'activity_id' => $activity->id]);

        $page = $this->asAdmin()->get(route('admin.customers.show', $customer));

        $page->assertOk()->assertSee('Coral Reef Dive')->assertSee('1,500.00');
        $summary = app(CustomerManagementService::class)->summary($customer);
        $this->assertSame(1, $summary['bookings_total']);
        $this->assertSame(1, $summary['bookings_live']);
        $this->assertSame(1500.0, $summary['total_paid']);
        $this->assertSame(1, $summary['reviews']);
        $this->assertSame(1, $summary['favorites']);
    }

    public function test_the_buttons_on_the_detail_page_follow_the_permissions(): void
    {
        [, $customer] = $this->makeCustomer();

        $admin = $this->actingAs($this->makeAdmin('admin'), 'web')->get(route('admin.customers.show', $customer));
        $admin->assertSee('value="DELETE"', false)->assertSee(route('admin.customers.suspend', $customer))->assertSee(route('admin.customers.edit', $customer));

        $support = $this->actingAs($this->makeAdmin('support_admin'), 'web')->get(route('admin.customers.show', $customer));
        $support->assertOk()->assertDontSee('value="DELETE"', false)->assertDontSee(route('admin.customers.suspend', $customer))->assertDontSee(route('admin.customers.edit', $customer));
    }

    public function test_a_deleted_customer_offers_restore_and_nothing_else(): void
    {
        [$user, $customer] = $this->makeCustomer();
        $user->delete();

        $this->asAdmin()->get(route('admin.customers.show', $customer))
            ->assertSee(route('admin.customers.restore', $customer))
            ->assertDontSee(route('admin.customers.suspend', $customer))
            ->assertDontSee('value="DELETE"', false);
    }

    // --- Menu and language -------------------------------------------------------------------

    public function test_the_sidebar_links_to_customers_for_those_who_may_view_them(): void
    {
        $link = route('admin.customers.index');

        $this->asAdmin('support_admin')->get(route('admin.dashboard'))->assertSee($link);
        $this->asAdmin('content_admin')->get(route('admin.dashboard'))->assertDontSee($link);
    }

    public function test_every_customer_screen_is_fully_translated_in_both_languages(): void
    {
        [, $customer] = $this->makeCustomer();
        $admin = $this->makeAdmin();

        $this->inEachAdminLanguage(function (string $locale) use ($admin, $customer) {
            foreach (['index' => route('admin.customers.index'), 'create' => route('admin.customers.create'), 'show' => route('admin.customers.show', $customer), 'edit' => route('admin.customers.edit', $customer)] as $name => $url) {
                $response = $this->actingAs($admin, 'web')->get($url)->assertOk();
                $this->assertNoRawTranslationKeys($response, "({$name}, {$locale})");
            }
        });
    }
}
