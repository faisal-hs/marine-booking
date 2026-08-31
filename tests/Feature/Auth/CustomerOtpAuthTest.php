<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOtpAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_phone_can_request_registration_otp_with_name(): void
    {
        $response = $this->postJson('/api/v1/auth/customer/otp/request', [
            'phone' => '0544444499',
            'name' => 'Tariq Traveler',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('otps', [
            'phone' => '0544444499',
            'type' => 'registration',
        ]);
    }

    public function test_full_registration_flow_via_otp(): void
    {
        $this->postJson('/api/v1/auth/customer/otp/request', [
            'phone' => '0544444499',
            'name' => 'Tariq Traveler',
        ]);

        $response = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => '0544444499',
            'code' => '1234',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.profile.type', 'customer')
            ->assertJsonPath('data.user.phone', '0544444499');

        $this->assertDatabaseHas('users', [
            'phone' => '0544444499',
            'user_type' => 'customer',
        ]);
    }

    public function test_full_login_flow_via_otp(): void
    {
        $user = User::factory()->customer()->create(['phone' => '0555555599']);
        \App\Models\Customer::create(['user_id' => $user->id, 'preferred_language' => 'en']);

        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0555555599']);

        $response = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => '0555555599',
            'code' => '1234',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_provider_phone_cannot_request_customer_otp(): void
    {
        User::factory()->provider()->create(['phone' => '0566666699']);

        $response = $this->postJson('/api/v1/auth/customer/otp/request', [
            'phone' => '0566666699',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_authenticated_customer_can_logout(): void
    {
        $this->postJson('/api/v1/auth/customer/otp/request', [
            'phone' => '0544444499',
            'name' => 'Tariq Traveler',
        ]);

        $verify = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => '0544444499',
            'code' => '1234',
        ]);

        $token = $verify->json('data.token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/customer/logout');

        $response->assertOk()->assertJson(['success' => true]);
    }
}
