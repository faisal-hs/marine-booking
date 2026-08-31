<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Seeds the one and only bootstrap Super Admin account.
     * Admins are never self-registered (see Module 01 analysis) — this
     * seeder is the sole way an admin account initially comes into existence.
     */
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

        $this->command->info('Admin seeded: admin@marine-booking.test / password');
    }
}
