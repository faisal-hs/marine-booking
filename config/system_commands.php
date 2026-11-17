<?php

/*
|--------------------------------------------------------------------------
| Running artisan commands from the admin API
|--------------------------------------------------------------------------
|
| POST /api/v1/admin/system/migrate, /migrate-fresh and /seed, for an installation with no shell. Super admin only, each command behind a permission of its own
| (system.migrate, system.migrate_fresh, system.seed). See App\Services\SystemCommandService.
|
*/

return [

    // `migrate:fresh` DROPS EVERY TABLE. In production it is refused unless this is switched on, deliberately, in the environment: and even then it needs the
    // confirmation phrase and the acting admin's password. (Outside production it needs those two, and nothing else.)
    'allow_migrate_fresh_in_production' => (bool) env('SYSTEM_COMMANDS_ALLOW_MIGRATE_FRESH_IN_PRODUCTION', false),

    // The only seeders the API will run, by class name in Database\Seeders. Bulk seeders (large, slow) are not offered.
    'seeders' => [
        // Reference data and fills: harmless to run in production, and safe to run again. (DatabaseSeeder seeds the roles and permissions only, in production.)
        'safe' => ['RolesAndPermissionsSeeder', 'CategorySeeder', 'CitySeeder', 'FeatureDefinitionSeeder', 'ProviderApplicationsSeeder', 'DatabaseSeeder'],

        // Demo data: accounts with a known password and made-up bookings, reviews, withdrawals and notifications. Refused in production.
        'demo' => ['AdminSeeder', 'AdminRoleUsersSeeder', 'DemoUsersSeeder', 'ProviderSeeder', 'CustomerSeeder', 'ActivitySeeder', 'ProviderMonthlyReservationSeeder', 'ProviderAnalyticsDemoSeeder',
            'ActivityReviewsDemoSeeder', 'ProviderWithdrawalsDemoSeeder', 'NotificationsDemoSeeder', 'ProviderDemoReviewSeeder'],
    ],

];
