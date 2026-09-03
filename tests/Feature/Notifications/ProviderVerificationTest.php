<?php

namespace Tests\Feature\Notifications;

use App\Models\Admin;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\Providers\Admin\ProviderRejectedAdminNotification;
use App\Notifications\Providers\Admin\ProviderVerifiedAdminNotification;
use App\Notifications\Providers\ProviderRejectedNotification;
use App\Notifications\Providers\ProviderVerifiedNotification;
use App\Services\ProviderVerificationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProviderVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(string $role): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function makePendingProvider(): array
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);

        return [$provider, $providerUser];
    }

    // --- Service ---

    public function test_verify_sets_status_and_fires_event(): void
    {
        [$provider, $providerUser] = $this->makePendingProvider();

        $verified = app(ProviderVerificationService::class)->verify($provider);

        $this->assertEquals('verified', $verified->verification_status);
    }

    public function test_reject_sets_status_and_reason(): void
    {
        [$provider, $providerUser] = $this->makePendingProvider();

        $rejected = app(ProviderVerificationService::class)->reject($provider, 'Missing license');

        $this->assertEquals('rejected', $rejected->verification_status);
        $this->assertEquals('Missing license', $rejected->rejection_reason);
    }

    public function test_cannot_verify_an_already_verified_provider(): void
    {
        [$provider, $providerUser] = $this->makePendingProvider();
        app(ProviderVerificationService::class)->verify($provider);

        $this->expectException(ValidationException::class);

        app(ProviderVerificationService::class)->verify($provider->fresh());
    }

    // --- Notifications ---

    public function test_verifying_a_provider_notifies_provider_and_admins(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$provider, $providerUser] = $this->makePendingProvider();

        app(ProviderVerificationService::class)->verify($provider);

        NotificationFacade::assertSentTo($providerUser, ProviderVerifiedNotification::class);
        NotificationFacade::assertSentTo($superAdmin, ProviderVerifiedAdminNotification::class);
    }

    public function test_rejecting_a_provider_notifies_provider_and_admins(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$provider, $providerUser] = $this->makePendingProvider();

        app(ProviderVerificationService::class)->reject($provider, 'Missing license');

        NotificationFacade::assertSentTo($providerUser, ProviderRejectedNotification::class);
        NotificationFacade::assertSentTo($superAdmin, ProviderRejectedAdminNotification::class);
    }

    // --- Admin HTTP/Blade surface ---

    public function test_admin_with_permission_can_verify_a_provider(): void
    {
        $admin = $this->makeAdmin('super_admin');
        [$provider, $providerUser] = $this->makePendingProvider();

        $response = $this->actingAs($admin, 'web')->post(route('admin.providers.verify', $provider));

        $response->assertRedirect();
        $this->assertEquals('verified', $provider->fresh()->verification_status);
    }

    public function test_admin_without_permission_cannot_verify_a_provider(): void
    {
        $admin = $this->makeAdmin('content_admin'); // no providers.verify
        [$provider, $providerUser] = $this->makePendingProvider();

        $response = $this->actingAs($admin, 'web')->post(route('admin.providers.verify', $provider));

        $response->assertStatus(403);
        $this->assertEquals('pending', $provider->fresh()->verification_status);
    }

    public function test_support_admin_cannot_verify_a_provider(): void
    {
        // support_admin has providers.view but NOT providers.verify,
        // matching the same view/mutate split established for payments.
        $admin = $this->makeAdmin('support_admin');
        [$provider, $providerUser] = $this->makePendingProvider();

        $response = $this->actingAs($admin, 'web')->post(route('admin.providers.verify', $provider));

        $response->assertStatus(403);
    }

    public function test_admin_can_reject_a_provider_with_a_reason(): void
    {
        $admin = $this->makeAdmin('super_admin');
        [$provider, $providerUser] = $this->makePendingProvider();

        $response = $this->actingAs($admin, 'web')
            ->post(route('admin.providers.reject', $provider), ['rejection_reason' => 'Missing license']);

        $response->assertRedirect();
        $this->assertEquals('rejected', $provider->fresh()->verification_status);
        $this->assertEquals('Missing license', $provider->fresh()->rejection_reason);
    }

    public function test_reject_requires_a_reason(): void
    {
        $admin = $this->makeAdmin('super_admin');
        [$provider, $providerUser] = $this->makePendingProvider();

        $response = $this->actingAs($admin, 'web')->post(route('admin.providers.reject', $provider), []);

        $response->assertSessionHasErrors('rejection_reason');
    }

    public function test_admin_can_view_providers_index_page(): void
    {
        $admin = $this->makeAdmin('super_admin');
        [$provider, $providerUser] = $this->makePendingProvider();

        $response = $this->actingAs($admin, 'web')->get(route('admin.providers.index'));

        $response->assertOk()->assertSee('Test Diving');
    }
}
