<?php

namespace Tests\Feature\Profile;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedCustomer(): array
    {
        $user = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $user->id, 'preferred_language' => 'en']);
        $token = $user->createToken('test', ['customer'])->plainTextToken;

        return [$user, $customer, $token];
    }

    public function test_customer_can_view_own_profile(): void
    {
        [$user, $customer, $token] = $this->authenticatedCustomer();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/customer/profile');

        $response->assertOk()->assertJsonPath('data.preferred_language', 'en');
    }

    public function test_customer_can_update_own_profile(): void
    {
        [$user, $customer, $token] = $this->authenticatedCustomer();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/customer/profile', [
                'preferred_language' => 'ar',
            ]);

        $response->assertOk()->assertJsonPath('data.preferred_language', 'ar');
    }

    public function test_provider_token_cannot_access_customer_profile(): void
    {
        $provider = User::factory()->provider()->create();
        $token = $provider->createToken('test', ['provider'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/customer/profile');

        $response->assertStatus(403);
    }
}
