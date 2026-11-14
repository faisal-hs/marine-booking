<?php

namespace Tests\Feature\Provider;

use App\Services\ProviderVerificationGate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The public homepage promises that every provider is verified before their activities go live. The admin approves the PROVIDER; nobody approves an
 * activity. These tests pin the rule down: an unverified provider cannot publish, an activity only goes live while its provider is an approved
 * account in good standing, and verifying the provider is what opens the flow.
 */
class ProviderVerificationGateTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function submit(string $token, $activity)
    {
        return $this->asProvider($token)->postJson("/api/v1/provider/activities/{$activity->uuid}/submit-for-review");
    }

    // --- Submitting -------------------------------------------------------

    public function test_a_provider_waiting_for_approval_cannot_submit_an_activity(): void
    {
        // Before approval a provider account can do nothing on the provider side at all, so it is
        // stopped at the door (403), long before the activity rules are reached.
        [, $provider, $token] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'draft');

        $response = $this->submit($token, $activity);

        $response->assertForbidden()->assertJsonPath('data.provider_request_status', 'not_submitted');
        $this->assertStringContainsString('not approved yet', $response->json('message'));
        $this->assertSame('draft', $activity->fresh()->status);
    }

    public function test_a_provider_whose_request_was_rejected_is_stopped_and_can_read_why(): void
    {
        [, $provider, $token] = $this->makeProvider('rejected');
        $activity = $this->makeActivity($provider, 'draft');

        $this->submit($token, $activity)->assertForbidden()->assertJsonPath('data.provider_request_status', 'rejected');
        $this->assertSame('draft', $activity->fresh()->status);

        // The one thing still open is the profile, which says what happened.
        $this->app['auth']->forgetGuards();
        $profile = $this->asProvider($token)->getJson('/api/v1/provider/profile')->assertOk();
        $this->assertSame('rejected', $profile->json('data.verification_status'));
        $this->assertSame('Licence photo was unreadable', $profile->json('data.rejection_reason'));
    }

    public function test_a_verified_provider_can_publish_an_activity_by_themselves(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->submit($token, $activity)->assertOk();

        $this->assertSame('published', $activity->fresh()->status, 'No admin looks at it first.');
    }

    public function test_an_unapproved_provider_cannot_build_a_draft_either(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'draft', ['title' => 'As it was']);
        $category = $this->makeCategory();

        $this->asProvider($token)->putJson("/api/v1/provider/activities/{$activity->uuid}", ['title' => 'A better title'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->asProvider($token)->postJson('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'A new one'])->assertForbidden();

        $this->assertSame('As it was', $activity->fresh()->title);
        $this->assertSame(1, \App\Models\Activity::count(), 'Nothing new was created.');
    }

    public function test_only_a_draft_can_be_published_this_way(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');

        foreach (['approved', 'inactive'] as $status) {
            $activity = $this->makeActivity($provider, $status);

            $this->submit($token, $activity)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['status']);

            $this->assertSame($status, $activity->fresh()->status);
        }
    }

    public function test_publishing_an_activity_that_is_already_active_is_harmless(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published');
        $publishedAt = $activity->fresh()->published_at;

        $this->submit($token, $activity)->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertSame('published', $activity->fresh()->status);
        $this->assertEquals($publishedAt, $activity->fresh()->published_at, 'Nothing changed');
    }

    // --- Publishing ---------------------------------------------------------

    public function test_a_draft_can_be_edited_and_then_published(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asProvider($token)->putJson("/api/v1/provider/activities/{$activity->uuid}", ['description' => 'Fixed description'])->assertOk();
        $this->assertSame('draft', $activity->fresh()->status, 'Editing alone must not change the status.');

        $this->submit($token, $activity)->assertOk();

        $this->assertSame('published', $activity->fresh()->status);
    }

    public function test_verifying_the_provider_unblocks_the_whole_flow(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'draft');
        $admin = $this->makeAdmin();

        // 1. Blocked while pending.
        $this->submit($token, $activity)->assertForbidden();

        // 2. An admin verifies the provider. That is the ONLY approval there is.
        $this->actingAs($admin, 'web')->post(route('admin.providers.verify', $provider))->assertSessionHasNoErrors();
        $this->assertSame('verified', $provider->fresh()->verification_status);

        // 3. Now the provider publishes by themselves; no admin looks at the activity. (Sanctum looks at the web guard first, so forget the admin.)
        $this->app['auth']->forgetGuards();
        $this->submit($token, $activity)->assertOk();

        $this->assertSame('published', $activity->fresh()->status);
    }

    // --- The gate itself: a backstop for every way of going live ----------------------

    public function test_the_gate_lets_a_verified_provider_in_good_standing_through(): void
    {
        [, $provider] = $this->makeProvider('verified');

        app(ProviderVerificationGate::class)->assertCanPublish($provider->load('user'));

        $this->assertTrue(true);
    }

    public function test_the_gate_stops_an_unverified_rejected_suspended_or_deleted_provider(): void
    {
        $gate = app(ProviderVerificationGate::class);
        $refuse = function ($provider) use ($gate) {
            try {
                $gate->assertCanPublish($provider->fresh()->load('user'));
            } catch (ValidationException $e) {
                return $e->errors()['provider'][0];
            }

            return null;
        };

        [, $pending] = $this->makeProvider('pending');
        $this->assertStringContainsString('waiting for verification', $refuse($pending));

        [, $rejected] = $this->makeProvider('rejected');
        $rejected->update(['rejection_reason' => 'Documents do not match']);
        $this->assertStringContainsString('Documents do not match', $refuse($rejected));

        [$suspendedUser, $suspended] = $this->makeProvider('verified');
        $suspendedUser->update(['status' => 'suspended']);
        $this->assertStringContainsString('suspended', $refuse($suspended));

        [$deletedUser, $deleted] = $this->makeProvider('verified');
        $deletedUser->delete();
        $this->assertStringContainsString('deleted', $refuse($deleted));
    }

    public function test_an_activity_cannot_go_live_when_its_provider_stopped_being_in_good_standing(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');
        $user->update(['status' => 'suspended']);

        $this->expectException(ValidationException::class);

        app(\App\Services\ActivityPublishingService::class)->requestPublication($activity->refresh());
    }

    // --- What admins see ----------------------------------------------------

    public function test_the_admin_list_offers_no_approval_of_activities_only_suspension(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $live = $this->makeActivity($provider, 'published');
        $draft = $this->makeActivity($provider, 'draft');

        $page = $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.activities.index'));

        $page->assertOk()
            ->assertSee(route('admin.activities.suspend', $live))
            ->assertDontSee(route('admin.activities.suspend', $draft))
            ->assertDontSee('/approve')
            ->assertDontSee('/reject');
    }
}
