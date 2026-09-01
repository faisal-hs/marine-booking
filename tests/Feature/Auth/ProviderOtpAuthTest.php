<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderOtpAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_phone_requesting_otp_requires_registration_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0511111199',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_new_phone_can_request_registration_otp_with_full_details(): void
    {
        $response = $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0511111199',
            'owner_name' => 'Sara Owner',
            'business_name' => 'Coral Bay Diving',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('otps', [
            'phone' => '0511111199',
            'type' => 'registration',
        ]);
    }

    public function test_full_registration_flow_via_otp(): void
    {
        $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0511111199',
            'owner_name' => 'Sara Owner',
            'business_name' => 'Coral Bay Diving',
        ]);

        $response = $this->postJson('/api/v1/auth/provider/otp/verify', [
            'phone' => '0511111199',
            'code' => '1234', // dev default code, per config/otp.php
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.type', 'provider')
            ->assertJsonPath('data.user.business_name', 'Coral Bay Diving')
            ->assertJsonPath('data.user.slug', 'coral-bay-diving')
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertDatabaseHas('users', [
            'phone' => '0511111199',
            'user_type' => 'provider',
        ]);
    }

    public function test_existing_phone_requests_login_otp_not_registration(): void
    {
        $user = User::factory()->provider()->create(['phone' => '0522222299']);
        \App\Models\Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Existing Diving',
            'slug' => 'existing-diving',
        ]);

        $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0522222299',
        ])->assertOk();

        $this->assertDatabaseHas('otps', [
            'phone' => '0522222299',
            'type' => 'login',
        ]);
    }

    public function test_full_login_flow_via_otp(): void
    {
        $user = User::factory()->provider()->create(['phone' => '0522222299']);
        \App\Models\Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Existing Diving',
            'slug' => 'existing-diving',
        ]);

        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => '0522222299']);

        $response = $this->postJson('/api/v1/auth/provider/otp/verify', [
            'phone' => '0522222299',
            'code' => '1234',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_invalid_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0511111199',
            'owner_name' => 'Sara Owner',
            'business_name' => 'Coral Bay Diving',
        ]);

        $response = $this->postJson('/api/v1/auth/provider/otp/verify', [
            'phone' => '0511111199',
            'code' => '9999',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_customer_phone_cannot_request_provider_otp(): void
    {
        User::factory()->customer()->create(['phone' => '0533333399']);

        $response = $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0533333399',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_resend_reissues_otp_of_same_type(): void
    {
        $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0511111199',
            'owner_name' => 'Sara Owner',
            'business_name' => 'Coral Bay Diving',
        ]);

        // Force cooldown to have already elapsed so resend isn't blocked.
        Otp::where('phone', '0511111199')->update(['created_at' => now()->subMinutes(5)]);

        $response = $this->postJson('/api/v1/auth/provider/otp/resend', [
            'phone' => '0511111199',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('otps', [
            'phone' => '0511111199',
            'type' => 'registration',
            'consumed_at' => null,
        ]);
    }

    public function test_resend_is_blocked_during_cooldown(): void
    {
        $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0511111199',
            'owner_name' => 'Sara Owner',
            'business_name' => 'Coral Bay Diving',
        ]);

        $response = $this->postJson('/api/v1/auth/provider/otp/resend', [
            'phone' => '0511111199',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_authenticated_provider_can_logout(): void
    {
        $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0511111199',
            'owner_name' => 'Sara Owner',
            'business_name' => 'Coral Bay Diving',
        ]);

        $verify = $this->postJson('/api/v1/auth/provider/otp/verify', [
            'phone' => '0511111199',
            'code' => '1234',
        ]);

        $token = $verify->json('data.token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/provider/logout');

        $response->assertOk()->assertJson(['success' => true]);
    }
}
