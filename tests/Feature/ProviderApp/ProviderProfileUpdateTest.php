<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Admin;
use App\Models\ProviderProfileChange;
use App\Models\User;
use App\Notifications\Providers\Admin\ProviderProfileChangeRequestedAdminNotification;
use App\Notifications\Providers\ProviderProfileChangeReviewedNotification;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * PATCH /provider/profile (and the older PUT). Ordinary information changes at once. CRITICAL information (config/provider_app.php: the business name and
 * the business type) does not take effect until an admin approves it; the live profile keeps its value meanwhile.
 */
class ProviderProfileUpdateTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->approvedProvider(['business_name' => 'Red Sea Divers', 'business_type' => 'Diving center', 'bio' => 'Old bio']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function patchProfile(array $data, ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'PATCH', '/api/v1/provider/profile', $data);
    }

    private function live(): array
    {
        return DB::table('providers')->where('id', $this->a['provider']->id)->first(['business_name', 'business_type', 'bio'])
            ? (array) DB::table('providers')->where('id', $this->a['provider']->id)->first(['business_name', 'business_type', 'bio']) : [];
    }

    private function pending(): ?ProviderProfileChange
    {
        return ProviderProfileChange::where('provider_id', $this->a['provider']->id)->where('status', 'pending')->first();
    }

    private function adminSignedIn(): User
    {
        $admin = $this->makeAdmin();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web');

        return $admin;
    }

    // --- Ordinary information changes at once --------------------------------------------------------------------------------

    public function test_ordinary_fields_change_at_once_and_the_response_is_in_the_specs_shape(): void
    {
        $categories = [$this->makeCategory('Diving')->id, $this->makeCategory('Yacht')->id];

        $response = $this->patchProfile([
            'bio' => 'New bio', 'marina_name' => 'Obhur Marina', 'contact_phone' => '+966551112233', 'contact_email' => 'info@redsea.sa',
            'city' => 'Jeddah', 'area' => 'North Obhur', 'name' => 'Khaled Owner', 'category_ids' => $categories,
        ])->assertOk();

        $this->assertSame(['status', 'message', 'data'], array_keys($response->json()));
        $this->assertSame('Profile updated', $response->json('message'));
        $this->assertNull($response->json('data.pending_profile_change'));

        $provider = $this->a['provider']->refresh();
        $this->assertSame(['New bio', 'Obhur Marina', '+966551112233', 'info@redsea.sa', 'Jeddah', 'North Obhur'],
            [$provider->bio, $provider->marina_name, $provider->contact_phone, $provider->contact_email, $provider->city, $provider->area]);
        $this->assertEqualsCanonicalizing($categories, $provider->categories()->pluck('categories.id')->all());
        $this->assertSame('Khaled Owner', $this->a['user']->refresh()->name);
        $this->assertSame(0, ProviderProfileChange::count());
    }

    public function test_patch_can_clear_a_field_but_the_older_put_leaves_a_null_alone(): void
    {
        $this->patchProfile(['bio' => null])->assertOk();
        $this->assertNull($this->live()['bio']);

        $this->a['provider']->update(['bio' => 'Back again']);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->a['token'])->putJson('/api/v1/provider/profile', ['bio' => null, 'city' => 'Hurghada'])->assertOk();

        $this->assertSame('Back again', $this->live()['bio'], 'PUT has always ignored a null');
    }

    // --- Critical information waits for an admin -------------------------------------------------------------------------------

    public function test_a_critical_change_waits_and_the_live_profile_keeps_its_value(): void
    {
        $response = $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();

        $this->assertSame('Red Sea Divers', $response->json('data.business_name'), 'Customers keep seeing the current name');
        $this->assertSame('Red Sea Divers', $this->live()['business_name']);
        $this->assertStringContainsString('waiting for admin review', $response->json('message'));
        $this->assertSame(['business_name' => ['current' => 'Red Sea Divers', 'requested' => 'Blue Hole Divers']], $response->json('data.pending_profile_change.changes'));
        $this->assertSame('2026-11-15T12:00:00Z', $response->json('data.pending_profile_change.requested_at'));

        $pending = $this->pending();
        $this->assertSame(['business_name' => ['from' => 'Red Sea Divers', 'to' => 'Blue Hole Divers']], $pending->requested_changes);
        $this->assertSame($this->a['user']->id, $pending->requested_by_user_id);
    }

    public function test_the_older_get_profile_shows_what_is_waiting(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();

        $this->hit($this->a['token'], 'GET', '/api/v1/provider/profile')->assertOk()
            ->assertJsonPath('data.business_name', 'Red Sea Divers')
            ->assertJsonPath('data.pending_profile_change.changes.business_name.requested', 'Blue Hole Divers');
    }

    public function test_ordinary_and_critical_fields_sent_together_are_split(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers', 'business_type' => 'Yacht charter', 'bio' => 'Fresh bio'])->assertOk();

        $this->assertSame(['business_name' => 'Red Sea Divers', 'business_type' => 'Diving center', 'bio' => 'Fresh bio'], $this->live(), 'Only the bio changed');
        $this->assertSame(['business_name', 'business_type'], array_keys($this->pending()->requested_changes));
    }

    public function test_a_critical_field_sent_with_its_current_value_is_no_change_at_all(): void
    {
        $this->patchProfile(['business_name' => '  Red Sea Divers  ', 'business_type' => 'Diving center'])->assertOk();

        $this->assertSame(0, ProviderProfileChange::count());
    }

    public function test_the_older_put_follows_the_same_rule(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->a['token'])->putJson('/api/v1/provider/profile', ['business_name' => 'Blue Hole Divers', 'bio' => 'Via PUT'])->assertOk();

        $this->assertSame('Red Sea Divers', $response->json('data.business_name'));
        $this->assertSame('Via PUT', $response->json('data.bio'));
        $this->assertStringContainsString('waiting for admin review', $response->json('message'));
        $this->assertSame('Blue Hole Divers', $this->pending()->requested_changes['business_name']['to']);
    }

    public function test_asking_again_replaces_the_waiting_request_and_keeps_what_was_asked_before(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();
        $first = $this->pending();

        $this->patchProfile(['business_type' => 'Yacht charter'])->assertOk();
        $second = $this->pending();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('superseded', $first->refresh()->status, 'The older request is kept, as history');
        $this->assertSame(['business_name', 'business_type'], array_keys($second->requested_changes), 'Both are wanted now');
        $this->assertSame(1, ProviderProfileChange::where('status', 'pending')->count(), 'There is only ever one waiting');

        $this->patchProfile(['business_name' => 'Coral Divers'])->assertOk();
        $this->assertSame('Coral Divers', $this->pending()->requested_changes['business_name']['to'], 'The newest ask for a field wins');
    }

    public function test_asking_for_the_same_thing_twice_does_not_make_a_second_request(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();
        $id = $this->pending()->id;

        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();

        $this->assertSame($id, $this->pending()->id);
        $this->assertSame(1, ProviderProfileChange::count());
    }

    public function test_going_back_to_the_current_value_withdraws_that_field_and_cancels_an_empty_request(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers', 'business_type' => 'Yacht charter'])->assertOk();

        $this->patchProfile(['business_name' => 'Red Sea Divers'])->assertOk();
        $this->assertSame(['business_type'], array_keys($this->pending()->requested_changes), 'Only the name was withdrawn');

        $response = $this->patchProfile(['business_type' => 'Diving center'])->assertOk();
        $this->assertNull($this->pending());
        $this->assertNull($response->json('data.pending_profile_change'));
        $this->assertSame('cancelled', ProviderProfileChange::orderByDesc('id')->first()->status);
    }

    public function test_which_fields_are_critical_comes_from_the_configuration(): void
    {
        config(['provider_app.critical_profile_fields' => ['business_name', 'bio']]);

        $this->patchProfile(['bio' => 'Needs review now', 'business_type' => 'Yacht charter'])->assertOk();

        $this->assertSame('Old bio', $this->live()['bio'], 'The bio is critical in this configuration');
        $this->assertSame('Yacht charter', $this->live()['business_type'], 'And the business type is not');
        $this->assertSame(['bio'], array_keys($this->pending()->requested_changes));
    }

    // --- Who may ask, and what is accepted ---------------------------------------------------------------------------------------

    public function test_something_must_be_sent_and_what_is_sent_must_be_valid(): void
    {
        $this->patchProfile([])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        $inactive = $this->makeCategory('Closed');
        $inactive->update(['status' => 'inactive']);
        $other = $this->makeProvider('verified');

        foreach ([['contact_phone' => 'call me'], ['contact_email' => 'nope'], ['category_ids' => []], ['category_ids' => [$inactive->id]], ['category_ids' => [999999]],
            ['lat' => 120], ['email' => $other[0]->email], ['business_name' => '']] as $bad) {
            $this->patchProfile($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->assertSame(0, ProviderProfileChange::count());
    }

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        $this->patchProfile(['bio' => 'x'], $this->unapprovedProvider())->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'PATCH', '/api/v1/provider/profile', ['bio' => 'x'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'PATCH', '/api/v1/provider/profile', ['bio' => 'x'])->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_a_provider_only_ever_changes_their_own_profile(): void
    {
        $other = $this->approvedProvider(['business_name' => 'Someone Else']);

        $this->patchProfile(['business_name' => 'Hijacked', 'provider_id' => $other['provider']->id, 'id' => $other['provider']->id]);

        $this->assertSame('Someone Else', $other['provider']->refresh()->business_name);
        $this->assertSame($this->a['provider']->id, $this->pending()->provider_id);
    }

    // --- The admin decides ---------------------------------------------------------------------------------------------------------

    public function test_admins_who_manage_providers_are_told_a_change_is_waiting(): void
    {
        $admin = $this->makeAdmin();

        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();

        $note = $admin->notifications()->where('type', ProviderProfileChangeRequestedAdminNotification::class)->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Red Sea Divers', $note->data['message']);
        $this->assertStringContainsString('business name', $note->data['message']);
    }

    public function test_the_admin_page_shows_what_is_waiting_with_current_and_requested(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();
        $this->adminSignedIn();

        $this->get(route('admin.providers.show', $this->a['provider']))->assertOk()
            ->assertSee(__('admin.providers.profile_change.title'))->assertSee('Blue Hole Divers')->assertSee(__('admin.providers.profile_change.approve'));
    }

    public function test_approving_applies_the_change_and_tells_the_provider(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();
        $change = $this->pending();
        $admin = $this->adminSignedIn();

        $this->post(route('admin.providers.profile-changes.approve', [$this->a['provider'], $change]))->assertSessionHas('status', __('admin.providers.profile_change.approved'));

        $this->assertSame('Blue Hole Divers', $this->live()['business_name']);
        $change->refresh();
        $this->assertSame(['approved', $admin->id], [$change->status, $change->reviewed_by_user_id]);
        $this->assertNotNull($change->reviewed_at);
        $this->assertNull($this->pending());

        $note = $this->a['user']->notifications()->where('type', ProviderProfileChangeReviewedNotification::class)->first();
        $this->assertStringContainsString('approved', $note->data['message']);
        $this->assertSame('provider.profile_change.approved', $note->data['type']);
    }

    public function test_rejecting_needs_a_reason_leaves_the_profile_alone_and_tells_the_provider_why(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();
        $change = $this->pending();
        $this->adminSignedIn();

        $this->post(route('admin.providers.profile-changes.reject', [$this->a['provider'], $change]), [])->assertSessionHasErrors('rejection_reason');
        $this->assertTrue($change->refresh()->isPending(), 'Not decided without a reason');

        $this->post(route('admin.providers.profile-changes.reject', [$this->a['provider'], $change]), ['rejection_reason' => 'The name does not match your commercial registration'])
            ->assertSessionHas('status', __('admin.providers.profile_change.rejected'));

        $this->assertSame('Red Sea Divers', $this->live()['business_name']);
        $this->assertSame(['rejected', 'The name does not match your commercial registration'], [$change->refresh()->status, $change->rejection_reason]);

        $note = $this->a['user']->notifications()->where('type', ProviderProfileChangeReviewedNotification::class)->first();
        $this->assertStringContainsString('not approved', $note->data['message']);
        $this->assertStringContainsString('does not match your commercial registration', $note->data['message']);
    }

    public function test_a_decided_or_replaced_request_cannot_be_decided_again(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();
        $old = $this->pending();
        $this->patchProfile(['business_type' => 'Yacht charter'])->assertOk();   // replaces it
        $this->adminSignedIn();

        $this->post(route('admin.providers.profile-changes.approve', [$this->a['provider'], $old]))->assertSessionHasErrors('change');
        $this->assertSame('Red Sea Divers', $this->live()['business_name'], 'A replaced request changes nothing');

        $current = $this->pending();
        $this->post(route('admin.providers.profile-changes.approve', [$this->a['provider'], $current]))->assertSessionHas('status');
        $this->post(route('admin.providers.profile-changes.approve', [$this->a['provider'], $current]))->assertSessionHasErrors('change');
    }

    public function test_only_someone_who_may_update_providers_can_decide_and_the_change_must_belong_to_that_provider(): void
    {
        $this->patchProfile(['business_name' => 'Blue Hole Divers'])->assertOk();
        $change = $this->pending();

        // A staff account with no role: it may not update providers.
        $nobody = User::factory()->admin()->create();
        Admin::create(['user_id' => $nobody->id, 'job_title' => 'Intern']);
        $this->flushHeaders();
        $this->actingAs($nobody, 'web')->post(route('admin.providers.profile-changes.approve', [$this->a['provider'], $change]))->assertForbidden();
        $this->assertTrue($change->refresh()->isPending());

        // And an admin cannot decide it through a different provider's page.
        $other = $this->approvedProvider();
        $this->actingAs($this->makeAdmin(), 'web')->post(route('admin.providers.profile-changes.approve', [$other['provider'], $change]))->assertNotFound();
        $this->assertTrue($change->refresh()->isPending());
    }
}
