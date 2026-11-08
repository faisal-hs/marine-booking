<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * Coupons: about half the verified providers have made one to three, each funded by the provider that made it. Percent or fixed, with or without a
 * limit on uses, a cap, a smallest booking and an expiry date (some already past), a few switched off, and some limited to some of the provider's own
 * activities (none listed means all of them). A code is capitals and digits, unique among the provider's own. They were made long ago, so the
 * bookings that use them (BulkCouponRedemptionSeeder) can come after them. Running it again adds nothing.
 * Volume at scale 1: about one coupon per verified provider.
 * Prerequisites: BulkProviderSeeder, BulkActivitySeeder.
 */
class BulkCouponSeeder extends BulkSeeder
{
    private const WORDS = ['SUMMER', 'WELCOME', 'DIVE', 'EARLY', 'SPRING', 'FRIENDS', 'WEEKEND', 'REEF', 'SEA', 'FAMILY'];

    protected function seed(): void
    {
        $this->needs('providers', 'BulkProviderSeeder');
        $this->needs('activities', 'BulkActivitySeeder');

        $have = array_flip(DB::table('coupons')->distinct()->pluck('provider_id')->all());
        $pivot = [];

        foreach (DB::table('providers')->where('verification_status', 'verified')->orderBy('id')->pluck('id') as $providerId) {
            if (isset($have[$providerId]) || ! $this->stable("coupons:{$providerId}", 55)) {
                continue;
            }

            $activities = DB::table('activities')->where('provider_id', $providerId)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
            $used = [];

            foreach (range(1, $this->rand(1, 3)) as $n) {
                do {
                    $code = $this->pick(self::WORDS).$this->rand(5, 50);
                } while (isset($used[$code]));
                $used[$code] = true;

                $percent = $this->chance(60);
                $madeAt = $this->now->copy()->subDays($this->rand(300, 420));
                $expires = $this->chance(40) ? ($this->chance(35) ? $this->now->copy()->subDays($this->rand(1, 90)) : $this->now->copy()->addDays($this->rand(10, 200))) : null;

                $couponId = DB::table('coupons')->insertGetId([
                    'uuid' => $this->uuid('coupon', "{$providerId}-{$code}"), 'provider_id' => $providerId, 'code' => $code,
                    'type' => $percent ? 'percent' : 'fixed', 'value' => $percent ? $this->pick([5, 10, 15, 20, 25]) : $this->pick([25, 50, 100, 150]),
                    'usage_limit' => $this->chance(50) ? $this->pick([20, 50, 100, 500]) : null,
                    'max_discount' => $percent && $this->chance(30) ? $this->pick([100, 200, 300]) : null,
                    'min_order_amount' => $this->chance(25) ? $this->pick([200, 500, 1000]) : null,
                    'expires_at' => $expires ? $this->ts($expires) : null, 'is_active' => $this->chance(85) ? 1 : 0,
                    'created_at' => $this->ts($madeAt), 'updated_at' => $this->ts($madeAt),
                ]);
                $this->created++;

                if ($activities && $this->chance(30)) {
                    foreach (array_slice($activities, 0, $this->rand(1, min(3, count($activities)))) as $activityId) {
                        $pivot[] = ['coupon_id' => $couponId, 'activity_id' => $activityId];
                    }
                }
            }
        }

        DB::table('activity_coupon')->insertOrIgnore($pivot);
    }
}
