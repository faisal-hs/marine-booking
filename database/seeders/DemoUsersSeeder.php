<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    /**
     * Seeds one demo provider and one demo customer for local
     * Postman/manual testing, so login can be exercised immediately
     * after `migrate:fresh --seed` without registering first.
     */
    public function run(): void
    {
        $providerUser = User::firstOrCreate(
            ['email' => 'provider@marine-booking.test'],
            [
                'name' => 'Demo Provider Owner',
                'phone' => '0511111111',
                'password' => Hash::make('password'),
                'user_type' => 'provider',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        Provider::firstOrCreate(
            ['user_id' => $providerUser->id],
            [
                'business_name' => 'Red Sea Divers',
                'slug' => 'red-sea-divers',
                'bio' => 'Demo diving and snorkeling operator for local testing.',
                'verification_status' => 'verified',
            ]
        );

        $customerUser = User::firstOrCreate(
            ['email' => 'customer@marine-booking.test'],
            [
                'name' => 'Demo Customer',
                'phone' => '0522222222',
                'password' => Hash::make('password'),
                'user_type' => 'customer',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        Customer::firstOrCreate(
            ['user_id' => $customerUser->id],
            ['preferred_language' => 'en']
        );

        $this->command->info('Demo provider seeded: provider@marine-booking.test / password');
        $this->command->info('Demo customer seeded: customer@marine-booking.test / password');
    }
}
