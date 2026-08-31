<?php

namespace Tests\Feature\Profile;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedProvider(): array
    {
        $user = User::factory()->provider()->create();
        $provider = Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Original Diving',
            'slug' => 'original-diving',
        ]);

        $token = $user->createToken('test', ['provider'])->plainTextToken;

        return [$user, $provider, $token];
    }

    public function test_provider_can_view_own_profile(): void
    {
        [$user, $provider, $token] = $this->authenticatedProvider();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/provider/profile');

        $response->assertOk()->assertJsonPath('data.business_name', 'Original Diving');
    }

    public function test_provider_can_update_own_profile(): void
    {
        [$user, $provider, $token] = $this->authenticatedProvider();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/provider/profile', [
                'business_name' => 'Updated Diving Co',
                'bio' => 'New bio here.',
            ]);

        $response->assertOk()->assertJsonPath('data.business_name', 'Updated Diving Co');
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'business_name' => 'Updated Diving Co']);
    }

    public function test_customer_token_cannot_access_provider_profile(): void
    {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('test', ['customer'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/provider/profile');

        $response->assertStatus(403);
    }
}
