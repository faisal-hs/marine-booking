<?php

namespace Tests\Feature\Provider;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The public homepage promises that every provider is verified before their
 * activities go live. Nothing enforced it: verification_status was written by the
 * admin screen and counted on the dashboard, and that was all. These tests pin the
 * rule down: an unverified provider cannot get an activity into review, and an
 * unverified provider's activity cannot be approved or republished.
 *
 * Also covers resubmitting a rejected activity, which the rejection email has always
 * promised ("make the necessary changes and resubmit") but nothing allowed.
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

    public function test_a_provider_waiting_for_verification_cannot_submit_an_activity(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'draft');

        $response = $this->submit($token, $activity);

        $response->assertStatus(422)->assertJsonValidationErrors(['provider']);
        $this->assertStringContainsString('waiting for verification', $response->json('errors.provider.0'));
        $this->assertSame('draft', $activity->fresh()->status);
    }

    public function test_a_provider_whose_verification_was_rejected_cannot_submit_and_is_told_why(): void
    {
        [, $provider, $token] = $this->makeProvider('rejected');
        $activity = $this->makeActivity($provider, 'draft');

        $response = $this->submit($token, $activity);

        $response->assertStatus(422);
        $this->assertStringContainsString('was rejected', $response->json('errors.provider.0'));
        $this->assertStringContainsString('Licence photo was unreadable', $response->json('errors.provider.0'));
        $this->assertSame('draft', $activity->fresh()->status);
    }

    public function test_a_verified_provider_can_submit_an_activity(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->submit($token, $activity)->assertOk();

        $this->assertSame('pending_review', $activity->fresh()->status);
    }

    public function test_an_unverified_provider_can_still_build_a_draft(): void
    {
        // Not being verified yet must not stop someone preparing their listing while
        // their documents are checked: only entering the review queue is gated.
        [, $provider, $token] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asProvider($token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}", ['title' => 'A better title'])
            ->assertOk();

        $this->assertSame('A better title', $activity->fresh()->title);
    }

    public function test_only_draft_or_rejected_activities_can_be_submitted(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');

        foreach (['pending_review', 'published', 'inactive'] as $status) {
            $activity = $this->makeActivity($provider, $status);

            $this->submit($token, $activity)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['status']);

            $this->assertSame($status, $activity->fresh()->status);
        }
    }

    // --- Resubmitting a rejected activity -----------------------------------

    public function test_a_rejected_activity_can_be_edited_and_resubmitted(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'rejected', ['rejection_reason' => 'Photos are too dark']);

        $this->asProvider($token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}", ['description' => 'Fixed description'])
            ->assertOk();
        $this->assertSame('rejected', $activity->fresh()->status, 'Editing alone must not change the status.');

        $this->submit($token, $activity)->assertOk();

        $activity->refresh();
        $this->assertSame('pending_review', $activity->status);
        $this->assertNull($activity->rejection_reason, 'The reason belonged to the previous review.');
    }

    public function test_a_resubmitted_activity_can_be_approved_and_goes_live(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'rejected', ['rejection_reason' => 'Photos are too dark']);
        $admin = $this->makeAdmin();

        $this->submit($token, $activity)->assertOk();
        $this->actingAs($admin, 'web')->post(route('admin.activities.approve', $activity))->assertSessionHasNoErrors();

        $this->assertSame('published', $activity->fresh()->status);
    }

    // --- Approving and republishing -----------------------------------------

    public function test_an_activity_of_an_unverified_provider_cannot_be_approved(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'pending_review');

        $this->actingAs($this->makeAdmin(), 'web')
            ->from('/admin/activities')
            ->post(route('admin.activities.approve', $activity))
            ->assertRedirect('/admin/activities')
            ->assertSessionHasErrors('provider');

        $this->assertSame('pending_review', $activity->fresh()->status);
    }

    public function test_an_inactive_activity_of_an_unverified_provider_cannot_be_republished(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'inactive');

        $this->actingAs($this->makeAdmin(), 'web')
            ->from('/admin/activities')
            ->post(route('admin.activities.publish', $activity))
            ->assertSessionHasErrors('provider');

        $this->assertSame('inactive', $activity->fresh()->status);
    }

    public function test_verifying_the_provider_unblocks_the_whole_flow(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'draft');
        $admin = $this->makeAdmin();

        // 1. Blocked while pending.
        $this->submit($token, $activity)->assertStatus(422);

        // 2. An admin verifies the provider.
        $this->actingAs($admin, 'web')->post(route('admin.providers.verify', $provider))->assertSessionHasNoErrors();
        $this->assertSame('verified', $provider->fresh()->verification_status);

        // 3. Now the provider can submit, and the admin can approve.
        $this->submit($token, $activity)->assertOk();
        $this->actingAs($admin, 'web')->post(route('admin.activities.approve', $activity))->assertSessionHasNoErrors();

        $this->assertSame('published', $activity->fresh()->status);
    }

    // --- What admins see ----------------------------------------------------

    public function test_the_admin_list_flags_an_unverified_provider_and_offers_no_approve_button(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $activity = $this->makeActivity($provider, 'pending_review');

        $page = $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.activities.index'));

        $page->assertOk()
            ->assertSee(__('admin.activities.verify_provider_first'))
            ->assertDontSee(route('admin.activities.approve', $activity));
    }

    public function test_the_admin_list_offers_approve_for_a_verified_provider(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'pending_review');

        $page = $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.activities.index'));

        $page->assertOk()
            ->assertSee(route('admin.activities.approve', $activity))
            ->assertDontSee(__('admin.activities.verify_provider_first'));
    }
}
