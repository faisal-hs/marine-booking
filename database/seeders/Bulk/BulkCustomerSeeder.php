<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * The customer account of every bulk customer user, and of one in twenty approved providers (people who use
 * the customer side too).
 * Volume at scale 1: about 25,000, plus about 47 approved providers who also use the customer side. Language follows the script of the name (Arabic names prefer Arabic,
 * and a few English names do too); 70% have a date of birth.
 * Prerequisites: BulkUserSeeder, BulkProviderSeeder.
 */
class BulkCustomerSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('users', 'BulkUserSeeder', fn ($q) => $q->where('phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%'));
        $this->needs('providers', 'BulkProviderSeeder');

        $have = array_flip(DB::table('customers')->pluck('user_id')->all());

        $users = DB::table('users')->where('phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%')->orderBy('id')->get(['id', 'name', 'created_at']);
        $this->insert('customers', (function () use ($users, $have) {
            foreach ($users as $user) {
                if (! isset($have[$user->id])) {
                    $arabic = (bool) preg_match('/\p{Arabic}/u', $user->name);

                    yield $this->row($user->id, $user->created_at, $arabic || $this->chance(8) ? 'ar' : 'en');
                }
            }
        })());

        // Approved providers who also use the customer side.
        $dual = DB::table('providers')
            ->join('users', 'users.id', '=', 'providers.user_id')
            ->where('providers.verification_status', 'verified')
            ->where('users.phone', 'like', self::PROVIDER_PHONE_PREFIX.'%')
            ->orderBy('providers.id')
            ->get(['users.id', 'users.created_at', 'providers.id as provider_id']);

        $this->insert('customers', (function () use ($dual, $have) {
            foreach ($dual as $user) {
                if ($user->provider_id % 20 === 0 && ! isset($have[$user->id])) {
                    yield $this->row($user->id, $user->created_at, 'en');
                }
            }
        })());
    }

    private function row(int $userId, string $createdAt, string $language): array
    {
        return [
            'uuid' => $this->uuid('customer', $userId),
            'user_id' => $userId,
            'date_of_birth' => $this->chance(70) ? sprintf('%d-%02d-%02d', $this->rand(1955, 2006), $this->rand(1, 12), $this->rand(1, 28)) : null,
            'preferred_language' => $language,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }
}
