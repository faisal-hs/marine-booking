<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The staff profile and role of every bulk staff user.
 * Volume at scale 1: 40 (one per staff user): about 5% super admins, 20% admins, and the rest split between
 * support and content admins. There is always at least one super admin.
 * Prerequisites: BulkUserSeeder, and the roles (RolesAndPermissionsSeeder).
 */
class BulkAdminSeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $this->needs('users', 'BulkUserSeeder', fn ($q) => $q->where('phone', 'like', self::STAFF_PHONE_PREFIX.'%'));

        $roles = DB::table('roles')->where('guard_name', 'web')->pluck('id', 'name');
        foreach (['super_admin', 'admin', 'support_admin', 'content_admin'] as $needed) {
            if (! isset($roles[$needed])) {
                throw new RuntimeException("The role \"{$needed}\" does not exist. Run RolesAndPermissionsSeeder first.");
            }
        }

        $have = array_flip(DB::table('admins')->pluck('user_id')->all());
        $staff = DB::table('users')->where('phone', 'like', self::STAFF_PHONE_PREFIX.'%')->orderBy('id')->get(['id', 'created_at']);
        $admins = [];
        $assignments = [];

        foreach ($staff as $position => $user) {
            $role = match (true) {
                $position === 0 => 'super_admin',
                $position < max(2, (int) ceil(count($staff) * 0.05)) => 'super_admin',
                $position < max(3, (int) ceil(count($staff) * 0.25)) => 'admin',
                $position % 2 === 0 => 'support_admin',
                default => 'content_admin',
            };

            $assignments[] = ['role_id' => $roles[$role], 'model_type' => 'App\\Models\\User', 'model_id' => $user->id];

            if (! isset($have[$user->id])) {
                $admins[] = [
                    'uuid' => $this->uuid('admin', $user->id),
                    'user_id' => $user->id,
                    'job_title' => $this->pick($this->staffJobTitles()),
                    'created_at' => $user->created_at,
                    'updated_at' => $user->created_at,
                ];
            }
        }

        $this->insert('admins', $admins);
        $this->insert('model_has_roles', $assignments);
    }
}
