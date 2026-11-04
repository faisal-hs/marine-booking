<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * Push devices: 55% of active customers and providers have one, 15% have a second.
 * Volume at scale 1: about 18,000. Tokens are made up and unique; none of them reaches a real device.
 * Prerequisites: BulkUserSeeder.
 */
class BulkDeviceSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('users', 'BulkUserSeeder', fn ($q) => $q->where('phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%'));

        $have = array_flip(DB::table('devices')->distinct()->pluck('user_id')->all());
        $users = DB::table('users')->where('status', 'active')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%')->orWhere('phone', 'like', self::PROVIDER_PHONE_PREFIX.'%'))
            ->orderBy('id')->get(['id', 'created_at']);

        $this->insert('devices', (function () use ($users, $have) {
            foreach ($users as $user) {
                if (isset($have[$user->id]) || ! $this->stable("device-{$user->id}", 55)) {
                    continue;
                }

                for ($n = 0, $count = $this->stable("second-device-{$user->id}", 15 / 55 * 100) ? 2 : 1; $n < $count; $n++) {
                    $seen = $this->daysAgo(0, 90);

                    yield [
                        'user_id' => $user->id,
                        // 152 characters, like a real FCM registration token, and unique by construction.
                        'fcm_token' => substr(hash('sha256', "device-{$user->id}-{$n}").hash('sha256', "token-{$user->id}-{$n}").hash('sha256', "bulk-{$user->id}-{$n}"), 0, 152),
                        'platform' => $this->weighted(['android' => 55, 'ios' => 40, 'web' => 5]),
                        'last_seen_at' => $this->ts($seen),
                        'created_at' => $user->created_at,
                        'updated_at' => $this->ts($seen),
                    ];
                }
            }
        })());
    }
}
