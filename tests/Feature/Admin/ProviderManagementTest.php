<?php

namespace Tests\Feature\Admin;

use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Providers\ProviderVerifiedNotification;
use App\Services\ProviderManagementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsTranslated;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The Providers module: the list, create, edit, suspend, delete and restore, with the
 * permission each action needs, and the consequences each has beyond the account row
 * (published activities come off the public site; a live booking blocks a delete).
 */
class ProviderManagementTest extends TestCase
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
            'name' => 'Omar Said',
            'phone' => '0555000111',
            'email' => 'omar@bluehole.test',
            'business_name' => 'Blue Hole Dive Club',
            'business_license' => 'LIC-2026-77',
            'bio' => 'Family run since 2009.',
            'address' => 'Dahab, South Sinai',
            'lat' => 28.5,
            'lng' => 34.5,
            'commission_rate' => 12.5,
        ], $overrides);
    }

    private function asAdmin(string $role = 'super_admin'): static
    {
        return $this->actingAs($this->makeAdmin($role), 'web');
    }

    // --- Who may do what ---------------------------------------------------------

    /**
     * [role, action, expected status family]: "ok" means the request is accepted (200 or a
     * redirect), "denied" means 403. super admin and admin do everything; support admin
     * only looks; content admin and an account with no role get nothing.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function permissionMatrix(): array
    {
        $cases = [];
        $actions = ['index', 'show', 'create', 'store', 'edit', 'update', 'suspend', 'reactivate', 'destroy', 'restore'];
        $allowed = [
            'super_admin' => $actions,
            'admin' => $actions,
            'support_admin' => ['index', 'show'],
            'content_admin' => [],
            'none' => [],
        ];

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
        [, $provider] = $this->makeProvider('pending');

        // "restore" acts on a deleted provider, "reactivate" on a suspended one.
        if ($action === 'restore') {
            $provider->user->delete();
        }
        if ($action === 'reactivate') {
            $provider->user->update(['status' => 'suspended']);
        }

        $client = $this->actingAs($actor, 'web');
        $response = match ($action) {
            'index' => $client->get(route('admin.providers.index')),
            'show' => $client->get(route('admin.providers.show', $provider)),
            'create' => $client->get(route('admin.providers.create')),
            'store' => $client->post(route('admin.providers.store'), $this->validPayload()),
            'edit' => $client->get(route('admin.providers.edit', $provider)),
            'update' => $client->put(route('admin.providers.update', $provider), $this->validPayload(['phone' => '0555000222', 'email' => 'new@x.test'])),
            'suspend' => $client->post(route('admin.providers.suspend', $provider)),
            'reactivate' => $client->post(route('admin.providers.reactivate', $provider)),
            'destroy' => $client->delete(route('admin.providers.destroy', $provider)),
            'restore' => $client->post(route('admin.providers.restore', $provider)),
        };

        if ($expected === 'denied') {
            $this->assertContains($response->getStatusCode(), [302, 403], "{$role} must not be able to {$action}");
            // A guest-style redirect is only acceptable for the "no role" account (the panel refuses it entirely).
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
        [, $provider] = $this->makeProvider('pending');
        $before = $provider->only(['business_name', 'commission_rate']);
        $support = $this->makeAdmin('support_admin');

        $this->actingAs($support, 'web')->put(route('admin.providers.update', $provider), $this->validPayload())->assertForbidden();
        $this->actingAs($support, 'web')->post(route('admin.providers.suspend', $provider))->assertForbidden();
        $this->actingAs($support, 'web')->delete(route('admin.providers.destroy', $provider))->assertForbidden();

        $provider->refresh();
        $this->assertSame($before, $provider->only(['business_name', 'commission_rate']));
        $this->assertSame('active', $provider->user->status);
        $this->assertFalse($provider->user->trashed());
    }

    // --- The list ----------------------------------------------------------------

    public function test_the_list_can_be_searched_by_business_owner_phone_and_email(): void
    {
        [$u1, $p1] = $this->makeProvider('verified');
        $p1->update(['business_name' => 'Coral Divers']);
        $u1->update(['name' => 'Karim Nabil', 'phone' => '0501110001', 'email' => 'karim@coral.test']);
        [$u2, $p2] = $this->makeProvider('verified');
        $p2->update(['business_name' => 'Reef Riders']);
        $u2->update(['name' => 'Sara Adel', 'phone' => '0502220002', 'email' => 'sara@reef.test']);

        foreach (['Coral', 'Karim', '0501110001', 'karim@coral'] as $term) {
            $page = $this->asAdmin()->get(route('admin.providers.index', ['search' => $term]));
            $page->assertOk()->assertSee('Coral Divers')->assertDontSee('Reef Riders');
        }
    }

    public function test_the_list_can_be_filtered_by_verification_and_by_account_state(): void
    {
        [, $pending] = $this->makeProvider('pending');
        $pending->update(['business_name' => 'Pending Dive Co']);
        [, $verified] = $this->makeProvider('verified');
        $verified->update(['business_name' => 'Verified Dive Co']);
        [$suspendedUser, $suspended] = $this->makeProvider('verified');
        $suspended->update(['business_name' => 'Suspended Dive Co']);
        $suspendedUser->update(['status' => 'suspended']);
        [$deletedUser, $deleted] = $this->makeProvider('verified');
        $deleted->update(['business_name' => 'Deleted Dive Co']);
        $deletedUser->delete();

        $admin = $this->makeAdmin();
        $get = fn (array $q) => $this->actingAs($admin, 'web')->get(route('admin.providers.index', $q));

        $get(['status' => 'pending'])->assertSee('Pending Dive Co')->assertDontSee('Verified Dive Co');
        $get(['account' => 'suspended'])->assertSee('Suspended Dive Co')->assertDontSee('Verified Dive Co');
        $get(['account' => 'active'])->assertSee('Verified Dive Co')->assertDontSee('Suspended Dive Co');
        $get(['account' => 'deleted'])->assertSee('Deleted Dive Co')->assertDontSee('Verified Dive Co');

        // By default a deleted provider is not in the way.
        $get([])->assertSee('Verified Dive Co')->assertDontSee('Deleted Dive Co');
    }

    // --- Creating ----------------------------------------------------------------

    public function test_an_admin_can_create_a_provider(): void
    {
        $response = $this->asAdmin()->post(route('admin.providers.store'), $this->validPayload());

        $provider = Provider::where('business_name', 'Blue Hole Dive Club')->firstOrFail();
        $response->assertRedirect(route('admin.providers.show', $provider));

        $this->assertSame('provider', $provider->user->user_type);
        $this->assertSame('active', $provider->user->status);
        $this->assertNull($provider->user->password, 'Providers sign in by one-time code, so there is no password.');
        $this->assertSame('0555000111', $provider->user->phone);
        $this->assertSame('blue-hole-dive-club', $provider->slug);
        $this->assertSame('pending', $provider->verification_status);
        $this->assertSame('12.50', (string) $provider->commission_rate);
        $this->assertSame('LIC-2026-77', $provider->business_license);
    }

    public function test_the_new_provider_can_actually_sign_in_with_the_phone_the_admin_entered(): void
    {
        $this->asAdmin()->post(route('admin.providers.store'), $this->validPayload(['phone' => '0555000999']));
        $provider = Provider::where('business_name', 'Blue Hole Dive Club')->firstOrFail();

        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => '0555000999'])->assertSuccessful();
        $login = $this->postJson('/api/v1/auth/provider/otp/verify', ['phone' => '0555000999', 'code' => config('otp.default_code')]);

        $login->assertSuccessful();
        $this->assertNotEmpty($login->json('data.token'));
        $this->assertSame($provider->user_id, User::where('phone', '0555000999')->value('id'), 'Signing in must reach the account the admin created, not make a second one.');
        $this->assertSame(1, Provider::count());
    }

    public function test_two_providers_with_the_same_business_name_get_different_slugs(): void
    {
        $this->asAdmin()->post(route('admin.providers.store'), $this->validPayload(['phone' => '0555000001', 'email' => 'a@x.test']));
        $this->asAdmin()->post(route('admin.providers.store'), $this->validPayload(['phone' => '0555000002', 'email' => 'b@x.test']));

        $this->assertEqualsCanonicalizing(['blue-hole-dive-club', 'blue-hole-dive-club-1'], Provider::pluck('slug')->all());
    }

    public function test_an_admin_who_may_verify_can_create_a_provider_that_is_already_verified(): void
    {
        $this->asAdmin()->post(route('admin.providers.store'), $this->validPayload(['verify_now' => 1]));

        $provider = Provider::where('business_name', 'Blue Hole Dive Club')->firstOrFail();
        $this->assertSame('verified', $provider->verification_status);
        // Through the normal verification service, so the owner is told as usual.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $provider->user_id, 'type' => ProviderVerifiedNotification::class]);
    }

    public function test_someone_who_may_create_but_not_verify_cannot_create_a_verified_provider(): void
    {
        $actor = $this->makeAdmin('super_admin');
        $actor->syncRoles([]);
        $actor->givePermissionTo('providers.create', 'providers.view');

        $this->actingAs($actor, 'web')->post(route('admin.providers.store'), $this->validPayload(['verify_now' => 1]))
            ->assertSessionHasErrors('verify_now');

        $this->assertSame(0, Provider::count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPayloads(): array
    {
        return [
            'no owner name' => [['name' => ''], 'name'],
            'no phone' => [['phone' => ''], 'phone'],
            'no business name' => [['business_name' => ''], 'business_name'],
            'a bad email' => [['email' => 'not-an-email'], 'email'],
            'latitude out of range' => [['lat' => 91], 'lat'],
            'longitude out of range' => [['lng' => -181], 'lng'],
            'commission above 100' => [['commission_rate' => 100.5], 'commission_rate'],
            'a negative commission' => [['commission_rate' => -1], 'commission_rate'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected_and_nothing_is_created(array $overrides, string $field): void
    {
        $this->asAdmin()->post(route('admin.providers.store'), $this->validPayload($overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, Provider::count());
        $this->assertSame(0, User::where('user_type', 'provider')->count());
    }

    public function test_a_phone_or_email_already_in_use_is_refused_even_by_a_deleted_account(): void
    {
        [$existing] = $this->makeProvider('verified');
        $existing->update(['phone' => '0555111222', 'email' => 'taken@x.test']);
        $existing->delete();

        $this->asAdmin()->post(route('admin.providers.store'), $this->validPayload(['phone' => '0555111222']))->assertSessionHasErrors('phone');
        $this->asAdmin()->post(route('admin.providers.store'), $this->validPayload(['email' => 'taken@x.test']))->assertSessionHasErrors('email');
    }

    // --- Editing -----------------------------------------------------------------

    public function test_an_admin_can_edit_the_business_and_the_owners_contact_details(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $slug = $provider->slug;

        $this->asAdmin()->put(route('admin.providers.update', $provider), $this->validPayload([
            'name' => 'Renamed Owner', 'phone' => '0555777888', 'business_name' => 'Renamed Business', 'commission_rate' => 7,
        ]))->assertRedirect(route('admin.providers.show', $provider));

        $provider->refresh();
        $this->assertSame('Renamed Business', $provider->business_name);
        $this->assertSame('Renamed Owner', $provider->user->name);
        $this->assertSame('0555777888', $provider->user->phone);
        $this->assertSame('7.00', (string) $provider->commission_rate);
        $this->assertSame($slug, $provider->slug, 'The public address must not change when a business is renamed.');
    }

    public function test_editing_cannot_change_the_verification_status_or_the_account_state(): void
    {
        [, $provider] = $this->makeProvider('pending');

        $this->asAdmin()->put(route('admin.providers.update', $provider), $this->validPayload([
            'phone' => '0555333444', 'verification_status' => 'verified', 'status' => 'suspended',
        ]))->assertSessionHasNoErrors();

        $provider->refresh();
        $this->assertSame('pending', $provider->verification_status, 'Verification goes through verify/reject, with its notification.');
        $this->assertSame('active', $provider->user->status);
    }

    public function test_the_providers_own_phone_and_email_are_not_a_conflict_when_saving(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $user->update(['phone' => '0555909090', 'email' => 'mine@x.test']);

        $this->asAdmin()->put(route('admin.providers.update', $provider), $this->validPayload(['phone' => '0555909090', 'email' => 'mine@x.test']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_email_can_be_cleared_and_an_empty_commission_keeps_the_old_rate(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $user->update(['email' => 'old@x.test']);
        $provider->update(['commission_rate' => 9]);

        $this->asAdmin()->put(route('admin.providers.update', $provider), $this->validPayload(['email' => '', 'commission_rate' => '']))
            ->assertSessionHasNoErrors();

        $provider->refresh();
        $this->assertNull($provider->user->email);
        $this->assertSame('9.00', (string) $provider->commission_rate);
    }

    // --- Suspending ----------------------------------------------------------------

    public function test_suspending_signs_the_provider_out_and_takes_published_activities_off_the_site(): void
    {
        [$user, $provider, $token] = $this->makeProvider('verified');
        $published = $this->makeActivity($provider, 'published');
        $draft = $this->makeActivity($provider, 'draft');
        $pending = $this->makeActivity($provider, 'pending_review');

        $this->asAdmin()->post(route('admin.providers.suspend', $provider))->assertSessionHasNoErrors();

        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame(0, $user->tokens()->count(), 'The token must stop working at once.');
        $this->assertSame('inactive', $published->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('pending_review', $pending->fresh()->status);
        $this->getJson("/api/v1/public/activities/{$published->slug}")->assertNotFound();
    }

    public function test_reactivating_does_not_put_activities_back_on_the_site(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published');
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.providers.suspend', $provider));
        $this->actingAs($admin, 'web')->post(route('admin.providers.reactivate', $provider))->assertSessionHasNoErrors();

        $this->assertSame('active', $user->fresh()->status);
        $this->assertSame('inactive', $activity->fresh()->status, 'Republishing is a deliberate admin decision.');
    }

    public function test_suspending_twice_is_harmless(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.providers.suspend', $provider))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'web')->post(route('admin.providers.suspend', $provider))->assertSessionHasNoErrors();

        $this->assertSame('suspended', $user->fresh()->status);
    }

    // --- Deleting and restoring --------------------------------------------------------

    public function test_deleting_soft_deletes_the_account_and_takes_the_activities_off_the_site(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published');
        $user->createToken('t', ['provider']);

        $this->asAdmin()->delete(route('admin.providers.destroy', $provider))->assertRedirect(route('admin.providers.index'));

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'deleted_at' => null]);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame('inactive', $activity->fresh()->status);
    }

    public function test_a_deleted_provider_leaves_the_default_list_but_stays_reachable(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $provider->update(['business_name' => 'Gone Diving']);
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->delete(route('admin.providers.destroy', $provider));

        $this->actingAs($admin, 'web')->get(route('admin.providers.index'))->assertDontSee('Gone Diving');
        $this->actingAs($admin, 'web')->get(route('admin.providers.index', ['account' => 'deleted']))->assertSee('Gone Diving')->assertSee(__('admin.providers.restore'));
        $this->actingAs($admin, 'web')->get(route('admin.providers.show', $provider))->assertOk()->assertSee(__('admin.accounts.deleted'));
    }

    public function test_a_deleted_provider_can_be_restored_but_activities_stay_off_until_republished(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published');
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->delete(route('admin.providers.destroy', $provider));

        $this->actingAs($admin, 'web')->post(route('admin.providers.restore', $provider))->assertRedirect(route('admin.providers.show', $provider));

        $this->assertFalse($user->fresh()->trashed());
        $this->assertSame('inactive', $activity->fresh()->status);
        $this->actingAs($admin, 'web')->get(route('admin.providers.index'))->assertSee($provider->fresh()->business_name);
    }

    public function test_deleting_twice_or_restoring_a_live_provider_is_harmless(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.providers.restore', $provider))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'web')->delete(route('admin.providers.destroy', $provider))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'web')->delete(route('admin.providers.destroy', $provider))->assertSessionHasNoErrors();
    }

    // --- A live booking blocks a delete --------------------------------------------------

    /** @return array<string, array{0: callable}> each closure turns a fresh booking into the state under test */
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
        [$user, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $date = $state === 'confirmed_today' ? Carbon::today() : null;
        $reservation = $this->bookActivity($customer, $activity, $option, $date);
        if ($state !== 'pending_hold') {
            $this->payFor($reservation);
        }

        $response = $this->asAdmin()->delete(route('admin.providers.destroy', $provider));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('1 upcoming booking', session('error'));
        $this->assertFalse($user->fresh()->trashed(), 'The provider must be untouched.');
        $this->assertSame('published', $activity->fresh()->status, 'And so must their activities.');
    }

    /** @return array<string, array{0: string}> */
    public static function historyStates(): array
    {
        return [
            'a cancelled booking' => ['cancelled'],
            'an expired hold' => ['expired'],
            'a completed booking' => ['completed'],
            'a confirmed booking that has already taken place' => ['confirmed_past'],
            'a pending booking whose hold ran out' => ['pending_lapsed'],
        ];
    }

    #[DataProvider('historyStates')]
    public function test_bookings_that_are_history_do_not_block_the_delete(string $state): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $reservation = $this->bookActivity($customer, $activity, $option);
        $this->payFor($reservation);

        match ($state) {
            'cancelled' => Reservation::whereKey($reservation->id)->update(['status' => 'cancelled']),
            'expired' => Reservation::whereKey($reservation->id)->update(['status' => 'expired']),
            'completed' => Reservation::whereKey($reservation->id)->update(['status' => 'completed']),
            'confirmed_past' => Reservation::whereKey($reservation->id)->update(['reservation_date' => Carbon::yesterday()->toDateString()]),
            'pending_lapsed' => Reservation::whereKey($reservation->id)->update(['status' => 'pending', 'hold_expires_at' => now()->subMinute()]),
        };

        $this->asAdmin()->delete(route('admin.providers.destroy', $provider))->assertSessionMissing('error');

        $this->assertTrue($user->fresh()->trashed(), "{$state} must not block deleting the provider.");
    }

    public function test_a_live_booking_on_a_deleted_activity_still_blocks_deleting_the_provider(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $this->payFor($this->bookActivity($customer, $activity, $option));
        // The activity was deleted some other way; the booking against it is still real.
        DB::table('activities')->where('id', $activity->id)->update(['deleted_at' => now()]);

        $this->asAdmin()->delete(route('admin.providers.destroy', $provider))->assertSessionHas('error');

        $this->assertFalse($user->fresh()->trashed());
    }

    // --- The detail page ------------------------------------------------------------------

    public function test_the_detail_page_shows_the_activities_bookings_and_revenue(): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider, 'published', ['title' => 'Reef Safari']);
        $this->makeActivity($provider, 'draft', ['title' => 'Unfinished Idea']);
        [, $customer] = $this->makeCustomer();
        $this->payFor($this->bookActivity($customer, $activity, $option));

        $page = $this->asAdmin()->get(route('admin.providers.show', $provider));

        $page->assertOk()
            ->assertSee('Reef Safari')
            ->assertSee('Unfinished Idea')
            ->assertSee('1,500.00');   // gross revenue: one 1500 booking, paid
        $summary = app(ProviderManagementService::class)->summary($provider);
        $this->assertSame(1, $summary['bookings_total']);
        $this->assertSame(1, $summary['bookings_live']);
        $this->assertSame(1500.0, $summary['gross_revenue']);
        $this->assertEqualsCanonicalizing(['published' => 1, 'draft' => 1], $summary['activities']);
    }

    public function test_the_buttons_on_the_detail_page_follow_the_permissions(): void
    {
        [, $provider] = $this->makeProvider('verified');

        $admin = $this->actingAs($this->makeAdmin('admin'), 'web')->get(route('admin.providers.show', $provider));
        // The delete button is a form that carries a DELETE method. (Its URL is also the show URL
        // and a prefix of every other provider URL, so the URL itself cannot be asserted on.)
        $admin->assertSee('value="DELETE"', false)->assertSee(route('admin.providers.suspend', $provider))->assertSee(route('admin.providers.edit', $provider));

        $support = $this->actingAs($this->makeAdmin('support_admin'), 'web')->get(route('admin.providers.show', $provider));
        $support->assertOk()->assertDontSee('value="DELETE"', false)->assertDontSee(route('admin.providers.suspend', $provider))->assertDontSee(route('admin.providers.edit', $provider));
    }

    public function test_a_deleted_provider_offers_restore_and_nothing_else(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $user->delete();

        $page = $this->asAdmin()->get(route('admin.providers.show', $provider));

        $page->assertSee(route('admin.providers.restore', $provider))
            ->assertDontSee(route('admin.providers.suspend', $provider))
            ->assertDontSee('value="DELETE"', false);
    }

    // --- Menu and language -------------------------------------------------------------------

    public function test_the_sidebar_links_to_providers_for_those_who_may_view_them(): void
    {
        $link = route('admin.providers.index');

        $this->asAdmin('support_admin')->get(route('admin.dashboard'))->assertSee($link);
        $this->asAdmin('content_admin')->get(route('admin.dashboard'))->assertDontSee($link);
    }

    public function test_every_provider_screen_is_fully_translated_in_both_languages(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $this->makeActivity($provider, 'draft');
        $admin = $this->makeAdmin();

        $this->inEachAdminLanguage(function (string $locale) use ($admin, $provider) {
            foreach (['index' => route('admin.providers.index'), 'create' => route('admin.providers.create'), 'show' => route('admin.providers.show', $provider), 'edit' => route('admin.providers.edit', $provider)] as $name => $url) {
                $response = $this->actingAs($admin, 'web')->get($url)->assertOk();
                $this->assertNoRawTranslationKeys($response, "({$name}, {$locale})");
            }
        });
    }
}
