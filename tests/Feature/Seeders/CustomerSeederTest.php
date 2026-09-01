<?php

namespace Tests\Feature\Seeders;

use App\Models\Customer;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_at_least_20_customers(): void
    {
        $this->seed(CustomerSeeder::class);

        $this->assertGreaterThanOrEqual(20, Customer::count());
    }

    public function test_every_customer_has_a_linked_active_user_with_unique_phone(): void
    {
        $this->seed(CustomerSeeder::class);

        $customers = Customer::with('user')->get();

        $this->assertCount($customers->count(), $customers->pluck('user.phone')->unique());

        foreach ($customers as $customer) {
            $this->assertNotNull($customer->user);
            $this->assertEquals('customer', $customer->user->user_type);
        }
    }

    public function test_preferred_language_is_a_realistic_mixture_not_all_english(): void
    {
        $this->seed(CustomerSeeder::class);

        $languages = Customer::distinct()->pluck('preferred_language');

        $this->assertContains('en', $languages);
        $this->assertContains('ar', $languages);
    }

    public function test_running_seeder_twice_does_not_duplicate_customers(): void
    {
        $this->seed(CustomerSeeder::class);
        $firstCount = Customer::count();

        $this->seed(CustomerSeeder::class);
        $secondCount = Customer::count();

        $this->assertEquals($firstCount, $secondCount);
    }
}
