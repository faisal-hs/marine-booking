<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@marine-booking.test'],
            [
                'name' => 'Super Admin',
                'phone' => '0500000000',
                'password' => Hash::make('password'),
                'user_type' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        Admin::firstOrCreate(
            ['user_id' => $user->id],
            ['job_title' => 'Super Administrator']
        );

        $user->syncRoles(['super_admin']);

        $this->command->info('Admin seeded: admin@marine-booking.test / password (super_admin)');
    }
}
