<?php

namespace Tests\Feature\Models;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_uuid_generated_on_creation(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->uuid);
        $this->assertTrue(strlen($user->uuid) === 36);
    }

    public function test_user_route_key_name_is_uuid(): void
    {
        $user = User::factory()->create();

        $this->assertEquals('uuid', $user->getRouteKeyName());
        $this->assertEquals($user->uuid, $user->getRouteKey());
    }

    public function test_provider_user_relationship(): void
    {
        $user = User::factory()->create(['user_type' => 'provider']);

        $provider = Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Red Sea Divers',
            'slug' => 'red-sea-divers',
        ]);

        $this->assertTrue($user->provider->is($provider));
        $this->assertTrue($provider->user->is($user));
        $this->assertEquals('pending', $provider->verification_status);
    }

    public function test_customer_user_relationship(): void
    {
        $user = User::factory()->create(['user_type' => 'customer']);

        $customer = Customer::create([
            'user_id' => $user->id,
            'preferred_language' => 'en',
        ]);

        $this->assertTrue($user->customer->is($customer));
        $this->assertTrue($customer->user->is($user));
    }

    public function test_admin_user_relationship(): void
    {
        $user = User::factory()->create(['user_type' => 'admin']);

        $admin = Admin::create([
            'user_id' => $user->id,
            'job_title' => 'Platform Manager',
        ]);

        $this->assertTrue($user->admin->is($admin));
    }

    public function test_user_type_helper_methods(): void
    {
        $provider = User::factory()->create(['user_type' => 'provider']);
        $customer = User::factory()->create(['user_type' => 'customer']);
        $admin = User::factory()->create(['user_type' => 'admin']);

        $this->assertTrue($provider->isProvider());
        $this->assertFalse($provider->isCustomer());

        $this->assertTrue($customer->isCustomer());
        $this->assertFalse($customer->isAdmin());

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isProvider());
    }

    public function test_deleting_user_cascades_to_provider_profile(): void
    {
        $user = User::factory()->create(['user_type' => 'provider']);
        $provider = Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Test Diving Co',
            'slug' => 'test-diving-co',
        ]);

        $user->forceDelete();

        $this->assertDatabaseMissing('providers', ['id' => $provider->id]);
    }
}
