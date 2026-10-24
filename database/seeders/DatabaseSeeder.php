<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // In production only the roles and permissions are seeded: the admin
        // panel cannot work without them, and they carry no credentials. The
        // demo seeders create accounts with a known password and fake data, so
        // they are skipped here and refuse to run if called directly.
        if (app()->isProduction()) {
            $this->command?->warn(
                'Production: seeded roles and permissions only. Skipped the demo seeders ' .
                    '(admin and demo accounts, providers, customers, activities). ' .
                    'Run CategorySeeder or FeatureDefinitionSeeder with --class if you want the starter reference data.'
            );

            $this->call(RolesAndPermissionsSeeder::class);

            return;
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminSeeder::class,
            AdminRoleUsersSeeder::class,
            DemoUsersSeeder::class,
            ProviderSeeder::class,
            CustomerSeeder::class,
            CategorySeeder::class,
            FeatureDefinitionSeeder::class,
            ActivitySeeder::class,
        ]);
    }
}
