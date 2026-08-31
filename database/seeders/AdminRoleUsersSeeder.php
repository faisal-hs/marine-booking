<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminRoleUsersSeeder extends Seeder
{
    /**
     * Seeds one demo admin per restricted role (beyond Super Admin, which
     * AdminSeeder already creates), so the dashboard's permission-gated
     * UI can be verified by logging in as each and comparing what's visible.
     */
    public function run(): void
    {
        $accounts = [
            ['email' => 'admin-manager@marine-booking.test', 'name' => 'Manager Admin', 'job_title' => 'Operations Manager', 'role' => 'admin'],
            ['email' => 'admin-support@marine-booking.test', 'name' => 'Support Admin', 'job_title' => 'Support Agent', 'role' => 'support_admin'],
            ['email' => 'admin-content@marine-booking.test', 'name' => 'Content Admin', 'job_title' => 'Content Manager', 'role' => 'content_admin'],
        ];

        foreach ($accounts as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make('password'),
                    'user_type' => 'admin',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            Admin::firstOrCreate(
                ['user_id' => $user->id],
                ['job_title' => $account['job_title']]
            );

            $user->syncRoles([$account['role']]);

            $this->command->info("Seeded {$account['role']}: {$account['email']} / password");
        }
    }
}
