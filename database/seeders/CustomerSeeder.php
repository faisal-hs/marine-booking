<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Seeds 22 customers, passwordless per Module 01's phone+OTP model.
     * Mirrors exactly what CustomerAuthService::verifyOtp() produces on a
     * real registration — 'password' is omitted entirely (not set to null),
     * since User::password uses Laravel's 'hashed' cast, which calls
     * Hash::make() on any non-null value passed to it; passing null
     * explicitly would throw, so the key must simply not be present.
     */
    public function run(): void
    {
        $names = [
            'John Carter',
            'Emily Watson',
            'Michael Chen',
            'Sophia Rossi',
            'David Kim',
            'Olivia Martin',
            'James Wilson',
            'Ava Thompson',
            'Daniel Brown',
            'Mia Garcia',
            'Liam Anderson',
            'Charlotte Lee',
            'Noah Davis',
            'Amelia White',
            'Ethan Clark',
            'Isabella Lewis',
            'Lucas Walker',
            'Grace Hall',
            'Benjamin Young',
            'Chloe Allen',
            'Henry Scott',
            'Zoe King',
        ];

        $languages = ['en', 'en', 'en', 'ar'];

        foreach ($names as $index => $name) {
            $phone = '05' . str_pad((string) (20000000 + $index), 8, '0', STR_PAD_LEFT);

            $user = User::firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $name,
                    'user_type' => 'customer',
                    'status' => 'active',
                    'phone_verified_at' => now(),
                ]
            );

            Customer::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'date_of_birth' => now()->subYears(18 + ($index % 40))->subDays($index * 7)->toDateString(),
                    'preferred_language' => $languages[$index % count($languages)],
                ]
            );
        }

        $this->command->info('Customers seeded: ' . count($names));
    }
}
