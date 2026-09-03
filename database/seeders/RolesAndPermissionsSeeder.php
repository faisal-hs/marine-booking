<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    protected array $allPermissions = [
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'providers.view',
        'providers.create',
        'providers.update',
        'providers.delete',
        'providers.verify',
        'providers.reject',
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.delete',
        'categories.view',
        'categories.create',
        'categories.update',
        'categories.delete',
        'activities.view',
        'activities.create',
        'activities.update',
        'activities.delete',
        'activities.approve',
        'activities.reject',
        'activities.publish',
        'feature_definitions.view',
        'feature_definitions.create',
        'feature_definitions.update',
        'feature_definitions.delete',
        'reservations.view',
        'reservations.create',
        'reservations.update',
        'reservations.cancel',
        'payments.view',
        'payments.refund',
        'notifications.view',
        'reports.view',
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'settings.view',
        'settings.update',
    ];

    public function run(): void
    {
        Cache::forget('spatie.permission.cache');

        foreach ($this->allPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions([
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'providers.view',
            'providers.create',
            'providers.update',
            'providers.delete',
            'providers.verify',
            'providers.reject',
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',
            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',
            'activities.view',
            'activities.create',
            'activities.update',
            'activities.delete',
            'feature_definitions.view',
            'feature_definitions.create',
            'feature_definitions.update',
            'feature_definitions.delete',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.cancel',
            'payments.view',
            'payments.refund',
            'notifications.view',
            'reports.view',
            'activities.approve',
            'activities.reject',
            'activities.publish',
        ]);

        $supportAdmin = Role::firstOrCreate(['name' => 'support_admin', 'guard_name' => 'web']);
        $supportAdmin->syncPermissions([
            'users.view',
            'providers.view',
            'customers.view',
            'reservations.view',
            'reservations.update',
            'reservations.cancel',
            'payments.view',
            'notifications.view',
        ]);

        $contentAdmin = Role::firstOrCreate(['name' => 'content_admin', 'guard_name' => 'web']);
        $contentAdmin->syncPermissions([
            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',
            'activities.view',
            'activities.create',
            'activities.update',
            'activities.delete',
            'feature_definitions.view',
            'feature_definitions.create',
            'feature_definitions.update',
            'feature_definitions.delete',
        ]);

        Role::firstOrCreate(['name' => 'provider', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->command->info('Roles and permissions seeded: super_admin, admin, support_admin, content_admin, provider, customer');
    }
}
