<?php

namespace Tests\Feature\Admin;

use App\Events\ActivitySuspended;
use App\Events\ActivitySuspensionLifted;
use App\Listeners\Notifications\SendActivitySuspendedNotifications;
use App\Listeners\Notifications\SendActivitySuspensionLiftedNotifications;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\Activities\ActivitySuspendedNotification;
use App\Notifications\Activities\ActivitySuspensionLiftedNotification;
use App\Services\DashboardService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The admin approves the PROVIDER account; nobody approves an activity. What an admin can do to an activity is stop it: a suspension, with a reason,
 * recorded apart from the provider's own pausing, that only an admin can lift. These tests cover who may, what it does, what it never does, and what
 * the provider is told.
 */
class ActivitySuspensionTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private User $admin;

    private User $providerUser;

    private $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeAdmin();
        [$this->providerUser, $this->provider] = $this->makeProvider('verified');
    }

    private function suspend(Activity $activity, string $reason = 'Customers reported that it did not match its description', ?User $as = null)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->admin, 'web')->post(route('admin.activities.suspend', $activity), ['suspension_reason' => $reason]);
    }

    private function lift(Activity $activity, ?User $as = null)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->admin, 'web')->post(route('admin.activities.lift', $activity));
    }

    private function listed(string $slug): bool
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/v1/public/activities/{$slug}")->getStatusCode() === 200;
    }

    // --- There is no approval of activities ---------------------------------------------------------------------

    public function test_there_is_no_way_for_an_admin_to_approve_reject_or_publish_an_activity(): void
    {
        foreach (['approve', 'reject', 'publish', 'deactivate'] as $retired) {
            $this->assertFalse(Route::has("admin.activities.{$retired}"), "admin.activities.{$retired} no longer exists");
        }

        $this->assertNull(Permission::where('name', 'activities.approve')->first());
        $this->assertNull(Permission::where('name', 'activities.reject')->first());
        $this->assertNull(Permission::where('name', 'activities.publish')->first());
    }

    // --- Suspending -----------------------------------------------------------------------------------------------

    public function test_an_admin_suspends_a_live_activity_with_a_reason(): void
    {
        $activity = $this->makeActivity($this->provider, 'published');
        $this->assertTrue($this->listed($activity->slug));

        $this->suspend($activity, 'Customers reported a safety problem')->assertSessionHasNoErrors()->assertSessionHas('status', __('admin.activities.suspended'));

        $activity->refresh();
        $this->assertSame('inactive', $activity->status);
        $this->assertTrue($activity->isSuspended());
        $this->assertSame('Customers reported a safety problem', $activity->suspension_reason);
        $this->assertSame($this->admin->id, $activity->suspended_by_user_id);
        $this->assertSame('published', $activity->suspended_from_status);
        $this->assertFalse($this->listed($activity->slug), 'Off the public site at once');
    }

    public function test_a_reason_is_required_and_a_refusal_changes_nothing(): void
    {
        $activity = $this->makeActivity($this->provider, 'published');

        foreach (['', 'ab', str_repeat('x', 501)] as $bad) {
            $this->suspend($activity, $bad)->assertSessionHasErrors('suspension_reason');
        }

        $activity->refresh();
        $this->assertSame('published', $activity->status);
        $this->assertFalse($activity->isSuspended());
    }

    public function test_a_ready_or_paused_activity_can_be_suspended_too_so_it_cannot_go_live(): void
    {
        foreach (['approved' => 'approved', 'inactive' => 'inactive'] as $status => $was) {
            $activity = $this->makeActivity($this->provider, $status);

            $this->suspend($activity)->assertSessionHasNoErrors();

            $activity->refresh();
            $this->assertSame(['inactive', true, $was], [$activity->status, $activity->isSuspended(), $activity->suspended_from_status]);
        }
    }

    public function test_a_draft_cannot_be_suspended_and_neither_can_a_suspended_one(): void
    {
        $draft = $this->makeActivity($this->provider, 'draft');
        $this->suspend($draft)->assertSessionHasErrors('status');
        $this->assertFalse($draft->fresh()->isSuspended());

        $live = $this->makeActivity($this->provider, 'published');
        $this->suspend($live, 'The first reason')->assertSessionHasNoErrors();
        $this->suspend($live, 'A second reason')->assertSessionHasErrors('status');
        $this->assertSame('The first reason', $live->fresh()->suspension_reason, 'The record of who stopped it and why is not overwritten');
    }

    // --- Lifting -----------------------------------------------------------------------------------------------------

    public function test_lifting_puts_the_activity_back_as_it_was(): void
    {
        foreach (['published' => 'published', 'approved' => 'approved', 'inactive' => 'inactive'] as $before => $after) {
            $activity = $this->makeActivity($this->provider, $before, $before === 'published' ? ['published_at' => now()->subMonth()] : []);
            $this->suspend($activity)->assertSessionHasNoErrors();

            $this->lift($activity)->assertSessionHasNoErrors();

            $activity->refresh();
            $this->assertSame($after, $activity->status, "Suspended while {$before}");
            $this->assertFalse($activity->isSuspended());
            $this->assertNull($activity->suspension_reason);
            $this->assertNull($activity->suspended_by_user_id);
            $this->assertNull($activity->suspended_from_status);
        }
    }

    public function test_a_lifted_live_activity_is_back_on_the_public_site(): void
    {
        $activity = $this->makeActivity($this->provider, 'published');
        $this->suspend($activity);
        $this->assertFalse($this->listed($activity->slug));

        $this->lift($activity)->assertSessionHas('status', __('admin.activities.lifted_live'));

        $this->assertTrue($this->listed($activity->slug));
    }

    public function test_lifting_does_not_put_a_live_activity_back_when_its_provider_is_no_longer_in_good_standing(): void
    {
        $activity = $this->makeActivity($this->provider, 'published');
        $this->suspend($activity);
        $this->providerUser->update(['status' => 'suspended']);

        $this->lift($activity)->assertSessionHasNoErrors()->assertSessionHas('status', __('admin.activities.lifted_hidden'));

        $activity->refresh();
        $this->assertSame('inactive', $activity->status);
        $this->assertFalse($activity->isSuspended(), 'The suspension is over; it is the provider who is not allowed to publish');
    }

    public function test_an_activity_that_is_not_suspended_cannot_be_lifted(): void
    {
        $activity = $this->makeActivity($this->provider, 'published');

        $this->lift($activity)->assertSessionHasErrors('status');

        $this->assertSame('published', $activity->fresh()->status);
    }

    // --- Who may ----------------------------------------------------------------------------------------------------

    public function test_only_an_admin_with_the_permission_may_suspend_or_lift(): void
    {
        $activity = $this->makeActivity($this->provider, 'published');

        foreach (['content_admin', 'support_admin'] as $role) {
            $this->suspend($activity, 'A reason', $this->makeAdmin($role))->assertForbidden();
        }
        $this->assertFalse($activity->fresh()->isSuspended());

        $this->suspend($activity, 'A reason', $this->makeAdmin('admin'))->assertSessionHasNoErrors();
        $this->assertTrue($activity->fresh()->isSuspended());

        $this->lift($activity, $this->makeAdmin('content_admin'))->assertForbidden();
        $this->assertTrue($activity->fresh()->isSuspended());
        $this->lift($activity, $this->makeAdmin('super_admin'))->assertSessionHasNoErrors();
    }

    public function test_a_provider_and_a_customer_cannot_reach_the_admin_routes(): void
    {
        // The admin middleware does not answer a non-admin with 403: it signs them out of the admin guard and sends them to the login page.
        $activity = $this->makeActivity($this->provider, 'published');

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->providerUser, 'web')->post(route('admin.activities.suspend', $activity), ['suspension_reason' => 'Mine to stop'])->assertRedirect(route('admin.login'));

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->customer()->create(), 'web')->post(route('admin.activities.suspend', $activity), ['suspension_reason' => 'Not theirs'])->assertRedirect(route('admin.login'));

        $this->assertFalse($activity->fresh()->isSuspended());
        $this->assertSame('published', $activity->fresh()->status);
    }

    // --- What the provider is told -----------------------------------------------------------------------------------

    public function test_the_provider_is_told_why_once_and_when_it_is_lifted(): void
    {
        $activity = $this->makeActivity($this->provider, 'published', ['title' => 'Sunset Cruise']);

        $this->suspend($activity, 'Customers reported a safety problem');

        $told = $this->providerUser->notifications()->where('type', ActivitySuspendedNotification::class)->get();
        $this->assertCount(1, $told, 'Once, although listeners are registered twice');
        $this->assertStringContainsString('Sunset Cruise', $told[0]->data['message']);
        $this->assertStringContainsString('Customers reported a safety problem', $told[0]->data['message']);

        $this->lift($activity);

        $lifted = $this->providerUser->notifications()->where('type', ActivitySuspensionLiftedNotification::class)->get();
        $this->assertCount(1, $lifted);
        $this->assertStringContainsString('live again', $lifted[0]->data['message']);
    }

    public function test_a_second_suspension_and_lift_of_the_same_activity_are_told_again(): void
    {
        $activity = $this->makeActivity($this->provider, 'published');

        foreach ([1, 2] as $round) {
            $this->travel($round * 2)->seconds();
            $this->suspend($activity, "Reason {$round}");
            $this->travel(1)->seconds();
            $this->lift($activity);
        }

        $this->assertSame(2, $this->providerUser->notifications()->where('type', ActivitySuspendedNotification::class)->count());
        $this->assertSame(2, $this->providerUser->notifications()->where('type', ActivitySuspensionLiftedNotification::class)->count());
    }

    public function test_one_event_handled_twice_tells_the_provider_once_even_when_a_second_ticks_between(): void
    {
        $activity = $this->makeActivity($this->provider, 'published');
        $this->suspend($activity);
        $activity->refresh();

        foreach ([ActivitySuspended::class => SendActivitySuspendedNotifications::class, ActivitySuspensionLifted::class => SendActivitySuspensionLiftedNotifications::class] as $event => $listener) {
            $once = new $event($activity);
            app($listener)->handle($once);
            $this->travel(2)->seconds();
            app($listener)->handle($once);   // the second registration of the same listener, a moment later
        }

        $this->assertSame(2, $this->providerUser->notifications()->where('type', ActivitySuspendedNotification::class)->count(), 'The setup suspension, plus the new event once although it was handled twice');
        $this->assertSame(1, $this->providerUser->notifications()->where('type', ActivitySuspensionLiftedNotification::class)->count());
    }

    public function test_two_suspensions_in_the_very_same_second_are_both_told(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $activity = $this->makeActivity($this->provider, 'published');

        foreach ([1, 2] as $round) {
            $this->suspend($activity, "Reason {$round}");
            $this->lift($activity);
        }
        Carbon::setTestNow();

        $this->assertSame(2, $this->providerUser->notifications()->where('type', ActivitySuspendedNotification::class)->count());
        $this->assertSame(2, $this->providerUser->notifications()->where('type', ActivitySuspensionLiftedNotification::class)->count());
    }

    public function test_a_lift_that_leaves_the_activity_hidden_says_so(): void
    {
        $activity = $this->makeActivity($this->provider, 'inactive');
        $this->suspend($activity);

        $this->lift($activity);

        $message = $this->providerUser->notifications()->where('type', ActivitySuspensionLiftedNotification::class)->first()->data['message'];
        $this->assertStringContainsString('still off the site until you activate it', $message);
    }

    // --- What the admin sees -------------------------------------------------------------------------------------------

    public function test_the_list_offers_suspend_for_a_live_activity_and_lift_for_a_suspended_one(): void
    {
        $live = $this->makeActivity($this->provider, 'published', ['title' => 'Live One']);
        $stopped = $this->makeActivity($this->provider, 'published', ['title' => 'Stopped One']);
        $this->suspend($stopped, 'A complaint was upheld');

        $page = $this->actingAs($this->admin, 'web')->get(route('admin.activities.index'));

        $page->assertOk()
            ->assertSee(route('admin.activities.suspend', $live))
            ->assertDontSee(route('admin.activities.lift', $live))
            ->assertSee(route('admin.activities.lift', $stopped))
            ->assertDontSee(route('admin.activities.suspend', $stopped))
            ->assertSee('A complaint was upheld')
            ->assertSee(__('admin.activities.statuses.suspended'));
    }

    public function test_the_suspended_filter_shows_only_admin_suspensions_not_the_providers_own_pause(): void
    {
        $paused = $this->makeActivity($this->provider, 'inactive', ['title' => 'Paused By Provider']);
        $stopped = $this->makeActivity($this->provider, 'published', ['title' => 'Stopped By Admin']);
        $this->suspend($stopped);

        $page = $this->actingAs($this->admin, 'web')->get(route('admin.activities.index', ['status' => 'suspended']));

        $page->assertOk()->assertSee('Stopped By Admin')->assertDontSee('Paused By Provider');
        $this->actingAs($this->admin, 'web')->get(route('admin.activities.index', ['status' => 'inactive']))->assertSee('Paused By Provider');
    }

    public function test_the_dashboard_counts_suspended_activities(): void
    {
        $this->suspend($this->makeActivity($this->provider, 'published'));
        $this->makeActivity($this->provider, 'inactive');

        $stats = app(DashboardService::class)->getStats();

        $this->assertSame(1, $stats['activities']['suspended']);
    }

    public function test_every_text_exists_in_both_languages(): void
    {
        foreach (['suspend', 'lift', 'confirm_suspend', 'reason_prompt', 'suspended', 'lifted_live', 'lifted_hidden', 'statuses.suspended', 'statuses.approved', 'statuses.inactive'] as $key) {
            $en = trans("admin.activities.{$key}", [], 'en');
            $ar = trans("admin.activities.{$key}", [], 'ar');

            $this->assertNotSame("admin.activities.{$key}", $en, "{$key} in English");
            $this->assertNotSame("admin.activities.{$key}", $ar, "{$key} in Arabic");
            $this->assertNotSame($en, $ar);
        }
        $this->assertNotSame('admin.dashboard.suspended_activities', trans('admin.dashboard.suspended_activities', [], 'ar'));
    }
}
