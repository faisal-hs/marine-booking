<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnauthorizedRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_token_cannot_call_provider_logout(): void
    {
        $this->postJson('/api/v1/auth/customer/otp/request', [
            'phone' => '0577777799',
            'name' => 'Cross Role Customer',
        ]);

        $verify = $this->postJson('/api/v1/auth/customer/otp/verify', [
            'phone' => '0577777799',
            'code' => '1234',
        ]);

        $customerToken = $verify->json('data.token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $customerToken)
            ->postJson('/api/v1/auth/provider/logout');

        $response->assertStatus(403);
    }

    public function test_provider_token_cannot_call_customer_logout(): void
    {
        $this->postJson('/api/v1/auth/provider/otp/request', [
            'phone' => '0588888899',
            'owner_name' => 'Cross Role Provider',
            'business_name' => 'Cross Role Diving',
        ]);

        $verify = $this->postJson('/api/v1/auth/provider/otp/verify', [
            'phone' => '0588888899',
            'code' => '1234',
        ]);

        $providerToken = $verify->json('data.token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $providerToken)
            ->postJson('/api/v1/auth/customer/logout');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/provider/logout');

        $response->assertStatus(401);
    }
}
