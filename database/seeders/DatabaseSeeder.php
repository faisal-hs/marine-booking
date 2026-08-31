<?php

namespace Database\Seeders;

use Database\Seeders\AdminSeeder;
use Database\Seeders\AdminRoleUsersSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminSeeder::class,
            AdminRoleUsersSeeder::class,
            DemoUsersSeeder::class,
        ]);
    }
}
