<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * Sign-in history: one-time codes issued long ago (login and registration), most of them used. EVERY one has
 * expired, and none uses the fixed default code, so none of this data can sign anyone in.
 * Volume at scale 1: 60,000 (a re-run only adds what is missing).
 * Prerequisites: BulkUserSeeder.
 */
class BulkOtpSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('users', 'BulkUserSeeder', fn ($q) => $q->where('phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%'));

        $users = DB::table('users')
            ->where(fn ($q) => $q->where('phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%')->orWhere('phone', 'like', self::PROVIDER_PHONE_PREFIX.'%'))
            ->orderBy('id')->get(['id', 'name', 'phone'])->all();

        // The table has no unique key to ignore duplicates on, so a re-run counts what is there and adds the rest.
        $existing = DB::table('otps')->where(fn ($q) => $q->where('phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%')->orWhere('phone', 'like', self::PROVIDER_PHONE_PREFIX.'%'))->count();
        $missing = max(0, $this->scaled(60000) - $existing);

        $this->insert('otps', (function () use ($users, $missing) {
            for ($i = 0; $i < $missing; $i++) {
                $user = $users[$this->rand(0, count($users) - 1)];
                $issued = $this->daysAgo(5, 400);
                $login = $this->chance(75);

                // Never the fixed default code, and four digits like the real ones.
                do {
                    $code = str_pad((string) $this->rand(0, 9999), 4, '0', STR_PAD_LEFT);
                } while ($code === (string) config('otp.default_code'));

                yield [
                    'user_id' => $login ? $user->id : null,
                    'phone' => $user->phone,
                    'code' => $code,
                    'type' => $login ? 'login' : 'registration',
                    'payload' => $login ? null : json_encode(['name' => $user->name]),
                    'expires_at' => $this->ts($issued->copy()->addMinutes(5)),
                    'consumed_at' => $this->chance(85) ? $this->ts($issued->copy()->addSeconds($this->rand(10, 180))) : null,
                    'created_at' => $this->ts($issued),
                    'updated_at' => $this->ts($issued),
                ];
            }
        })());
    }
}
