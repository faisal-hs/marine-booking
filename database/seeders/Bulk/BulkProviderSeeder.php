<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provider accounts, two kinds:
 *  - the people who REGISTERED as providers (the 06... phones): about 70% approved, 15% waiting, 15% rejected;
 *  - customers who ASKED to become one (the first 800 customer phones): about 35% approved (so they hold both
 *    accounts, 30% of them choosing the provider side as their default), 40% waiting, 25% rejected.
 *
 * Volume at scale 1: 1,500 + 800 provider accounts, spread over twelve cities with coordinates.
 * Prerequisites: BulkUserSeeder.
 */
class BulkProviderSeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $this->needs('users', 'BulkUserSeeder', fn ($q) => $q->where('phone', 'like', self::PROVIDER_PHONE_PREFIX.'%'));

        $have = array_flip(DB::table('providers')->pluck('user_id')->all());
        $chooseProvider = [];

        // Registered as providers.
        $registered = DB::table('users')->where('phone', 'like', self::PROVIDER_PHONE_PREFIX.'%')->orderBy('id')->get(['id', 'phone', 'created_at']);
        $this->insert('providers', (function () use ($registered, $have) {
            foreach ($registered as $user) {
                if (! isset($have[$user->id])) {
                    $status = $this->weighted(['verified' => 70, 'pending' => 15, 'rejected' => 15]);
                    yield $this->row($user->id, (int) substr($user->phone, 2), 'p', $status, $user->created_at);
                }
            }
        })());

        // Customers who asked to become providers: the lowest customer phones.
        $upgrades = $this->scaled(800);
        $customers = DB::table('users')->where('phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%')
            ->where('phone', '<', self::CUSTOMER_PHONE_PREFIX.str_pad((string) $upgrades, 8, '0', STR_PAD_LEFT))
            ->orderBy('id')->get(['id', 'phone', 'created_at']);
        $this->insert('providers', (function () use ($customers, $have, &$chooseProvider) {
            foreach ($customers as $user) {
                if (! isset($have[$user->id])) {
                    $status = $this->weighted(['verified' => 35, 'pending' => 40, 'rejected' => 25]);
                    if ($status === 'verified' && $this->chance(30)) {
                        $chooseProvider[] = $user->id;
                    }
                    yield $this->row($user->id, (int) substr($user->phone, 2), 'u', $status, $user->created_at);
                }
            }
        })());

        // A person who has both accounts and prefers the provider side.
        foreach (array_chunk($chooseProvider, 500) as $ids) {
            DB::table('users')->whereIn('id', $ids)->update(['default_account' => 'provider']);
        }
    }

    private function row(int $userId, int $index, string $kind, string $status, string $userCreatedAt): array
    {
        [$city, $areas, $lat, $lng] = $this->pick($this->cities());
        $business = $this->businessName();
        $created = Carbon::parse($userCreatedAt)->addDays($this->rand(0, 20));
        $created = $created->greaterThan($this->now) ? $this->now->copy() : $created;

        return [
            'uuid' => $this->uuid("provider-{$kind}", $index),
            'user_id' => $userId,
            'business_name' => $business,
            'slug' => Str::slug($business)."-{$kind}{$index}",
            'business_license' => $status === 'verified' ? 'LIC-'.$created->year.'-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT) : null,
            'bio' => $this->providerBio($business, $city),
            'logo' => null,
            'address' => $this->rand(1, 99).' '.$this->pick(['Corniche Road', 'Marina Street', 'Beach Road', 'Harbour Lane', 'Palm Avenue']),
            'city' => $city,
            'area' => $this->pick($areas),
            'lat' => round($lat + $this->rand(-300, 300) / 10000, 6),
            'lng' => round($lng + $this->rand(-300, 300) / 10000, 6),
            'verification_status' => $status,
            'rejection_reason' => $status === 'rejected' ? $this->pick($this->providerRejectionReasons()) : null,
            'commission_rate' => $status === 'verified' ? $this->pick([0, 5, 7.5, 10, 12.5, 15]) : 0,
            'deleted_at' => null,
            'created_at' => $this->ts($created),
            'updated_at' => $this->ts($created),
        ];
    }
}
