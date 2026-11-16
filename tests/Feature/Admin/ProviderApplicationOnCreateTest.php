<?php

namespace Tests\Feature\Admin;

use App\Models\Provider;
use App\Models\ProviderRequest;
use App\Models\User;
use App\Services\ProviderManagementService;
use App\Services\ProviderVerificationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * A provider that staff add from the admin panel has an application, as one who signs up does: a provider signs in and the provider app asks for the application
 * (GET /provider/apply), and "you have not submitted an application yet" is the wrong answer for someone staff have just added.
 */
class ProviderApplicationOnCreateTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Omar Said', 'phone' => '0555000111', 'email' => 'omar@bluehole.test', 'business_name' => 'Blue Hole Dive Club', 'business_license' => 'LIC-2026-77',
            'bio' => 'Family run since 2009.', 'address' => 'Dahab, South Sinai', 'lat' => 28.5, 'lng' => 34.5, 'commission_rate' => 12.5], $over);
    }

    private function signIn(string $phone): string
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => $phone])->assertSuccessful();

        return $this->postJson('/api/v1/auth/provider/otp/verify', ['phone' => $phone, 'code' => config('otp.default_code')])->assertSuccessful()->json('data.token');
    }

    public function test_a_provider_added_from_the_panel_has_a_pending_application_until_it_is_verified(): void
    {
        $staff = $this->makeAdmin('super_admin');

        $this->actingAs($staff, 'web')->post(route('admin.providers.store'), $this->payload())->assertRedirect();

        $provider = Provider::where('business_name', 'Blue Hole Dive Club')->firstOrFail();
        $application = ProviderRequest::where('provider_id', $provider->id)->get();
        $this->assertCount(1, $application);
        $this->assertSame([$provider->user_id, 'registration', 'pending', null, null], [$application[0]->user_id, $application[0]->source, $application[0]->status, $application[0]->reviewed_at, $application[0]->reviewed_by]);
        $this->assertNotNull($application[0]->submitted_at);
        $this->assertFalse($application[0]->submitted_at->isFuture());
    }

    public function test_added_and_verified_at_once_the_application_is_approved_by_the_staff_member(): void
    {
        $staff = $this->makeAdmin('super_admin');

        $this->actingAs($staff, 'web')->post(route('admin.providers.store'), $this->payload(['verify_now' => 1]))->assertRedirect();

        $provider = Provider::where('business_name', 'Blue Hole Dive Club')->firstOrFail();
        $this->assertSame('verified', $provider->verification_status);
        $application = ProviderRequest::where('provider_id', $provider->id)->get();
        $this->assertCount(1, $application, 'One application, not one made and one more by verifying');
        $this->assertSame(['approved', $staff->id], [$application[0]->status, $application[0]->reviewed_by]);
        $this->assertNotNull($application[0]->reviewed_at);
    }

    public function test_the_provider_added_by_staff_can_sign_in_and_ask_for_the_application(): void
    {
        $this->actingAs($this->makeAdmin('super_admin'), 'web')->post(route('admin.providers.store'), $this->payload(['verify_now' => 1]))->assertRedirect();
        $this->actingAs($this->makeAdmin('admin'), 'web');   // the staff session is not what signs the provider in

        $token = $this->signIn('0555000111');
        $r = $this->hit($token, 'GET', '/api/v1/provider/apply')->assertOk();

        $this->assertSame(['application_id', 'status', 'note', 'submitted_at', 'reviewed_at', 'can_resubmit', 'provider', 'stats'], array_keys($r->json('data')));
        $this->assertSame(['approved', false], [$r->json('data.status'), $r->json('data.can_resubmit')]);
    }

    public function test_a_provider_added_but_not_verified_asks_and_is_told_it_is_pending(): void
    {
        $this->actingAs($this->makeAdmin('super_admin'), 'web')->post(route('admin.providers.store'), $this->payload())->assertRedirect();

        $token = $this->signIn('0555000111');
        $d = $this->hit($token, 'GET', '/api/v1/provider/apply')->assertOk()->json('data');

        $this->assertSame(['pending', null, null, false], [$d['status'], $d['note'], $d['reviewed_at'], $d['can_resubmit']]);
    }

    public function test_rejecting_such_a_provider_later_rejects_its_application_and_it_may_apply_again(): void
    {
        $staff = $this->makeAdmin('super_admin');
        $this->actingAs($staff, 'web')->post(route('admin.providers.store'), $this->payload())->assertRedirect();
        $provider = Provider::where('business_name', 'Blue Hole Dive Club')->firstOrFail();

        app(ProviderVerificationService::class)->reject($provider, 'The licence has expired.', $staff);

        $d = $this->hit($this->signIn('0555000111'), 'GET', '/api/v1/provider/apply')->assertOk()->json('data');
        $this->assertSame(['rejected', 'The licence has expired.', true], [$d['status'], $d['note'], $d['can_resubmit']]);
        $this->assertSame(1, ProviderRequest::where('provider_id', $provider->id)->count());
    }

    public function test_a_request_that_is_refused_makes_no_application_and_no_account(): void
    {
        $this->makeProviderWithPhone('0555000111');
        $before = [User::count(), Provider::count(), ProviderRequest::count()];

        $this->actingAs($this->makeAdmin('super_admin'), 'web')->post(route('admin.providers.store'), $this->payload(['business_name' => 'Another Club']))->assertSessionHasErrors('phone');

        $this->assertSame([$before[0] + 1, $before[1], $before[2]], [User::count(), Provider::count(), ProviderRequest::count()], 'Only the admin made by this test: nothing from the refused request');
    }

    public function test_the_service_still_works_without_being_told_who_is_adding(): void
    {
        $provider = app(ProviderManagementService::class)->create($this->payload(), true);

        $application = ProviderRequest::where('provider_id', $provider->id)->firstOrFail();
        $this->assertSame(['approved', null], [$application->status, $application->reviewed_by]);
    }

    private function makeProviderWithPhone(string $phone): void
    {
        app(ProviderManagementService::class)->create($this->payload(['phone' => $phone, 'email' => 'first@club.test', 'business_name' => 'First Club']));
    }
}
