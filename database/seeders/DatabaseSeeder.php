<?php

namespace Database\Seeders;

use Database\Seeders\AdminSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Deliberately does NOT use WithoutModelEvents — several models
     * (e.g. anything using HasUuid) rely on the `creating` event firing
     * during seeding to populate required fields.
     */
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            DemoUsersSeeder::class,
        ]);
    }
}
