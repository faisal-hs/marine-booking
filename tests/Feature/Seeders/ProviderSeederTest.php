<?php

namespace Tests\Feature\Seeders;

use App\Models\Provider;
use Database\Seeders\ProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_at_least_20_providers(): void
    {
        $this->seed(ProviderSeeder::class);

        $this->assertGreaterThanOrEqual(20, Provider::count());
    }

    public function test_every_provider_has_a_linked_active_user_with_unique_phone(): void
    {
        $this->seed(ProviderSeeder::class);

        $providers = Provider::with('user')->get();

        $this->assertCount($providers->count(), $providers->pluck('user.phone')->unique());

        foreach ($providers as $provider) {
            $this->assertNotNull($provider->user);
            $this->assertEquals('provider', $provider->user->user_type);
            $this->assertEquals('active', $provider->user->status);
        }
    }

    public function test_providers_have_varied_verification_status_not_all_identical(): void
    {
        $this->seed(ProviderSeeder::class);

        $distinctStatuses = Provider::distinct()->pluck('verification_status');

        $this->assertGreaterThan(1, $distinctStatuses->count());
    }

    public function test_running_seeder_twice_does_not_duplicate_providers(): void
    {
        $this->seed(ProviderSeeder::class);
        $firstCount = Provider::count();

        $this->seed(ProviderSeeder::class);
        $secondCount = Provider::count();

        $this->assertEquals($firstCount, $secondCount);
    }
}
