<?php

namespace Database\Seeders\Bulk;

use App\Models\Coupon;
use App\Services\CouponService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Coupons used on bookings: about one booking in twenty of a provider that has a coupon used one, where the coupon was valid for it. The discount is
 * worked out by the SAME rule the app uses (CouponService::discountFor) and written onto the booking, so a seeded booking's total is its subtotal less
 * its discount, as a real one is. A coupon is only used on a booking made while it existed, before it expired, on an activity it covers, big enough
 * for its smallest booking, and never more times than its limit allows among the bookings that are still alive. This runs BEFORE the payments, which
 * take their amounts from the totals. Running it again adds nothing.
 * Volume at scale 1: about five thousand redemptions.
 * Prerequisites: BulkCouponSeeder, BulkReservationSeeder.
 */
class BulkCouponRedemptionSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('coupons', 'BulkCouponSeeder');
        $this->needs('reservations', 'BulkReservationSeeder');

        $service = app(CouponService::class);
        $taken = array_flip(DB::table('coupon_redemptions')->pluck('reservation_id')->all());
        $alive = DB::table('coupon_redemptions as r')->join('reservations as b', 'b.id', '=', 'r.reservation_id')->whereNotIn('b.status', CouponService::DEAD_BOOKINGS)
            ->selectRaw('r.coupon_id, COUNT(*) AS total')->groupBy('r.coupon_id')->pluck('total', 'coupon_id')->all();

        $coupons = DB::table('coupons')->orderBy('id')->get()->groupBy('provider_id');
        $restrictions = DB::table('activity_coupon')->get()->groupBy('coupon_id')->map(fn ($rows) => $rows->pluck('activity_id')->flip()->all());

        foreach ($coupons as $providerId => $providerCoupons) {
            $bookings = DB::table('reservations as b')->join('activities as a', 'a.id', '=', 'b.activity_id')->where('a.provider_id', $providerId)->whereNull('b.deleted_at')
                ->orderBy('b.id')->get(['b.id', 'b.activity_id', 'b.customer_id', 'b.subtotal', 'b.tax', 'b.status', 'b.created_at']);

            foreach ($providerCoupons as $row) {
                $coupon = new Coupon(['type' => $row->type, 'value' => $row->value, 'max_discount' => $row->max_discount]);
                $made = Carbon::parse($row->created_at);
                $until = $row->expires_at ? Carbon::parse($row->expires_at) : null;

                foreach ($bookings as $booking) {
                    $at = Carbon::parse($booking->created_at);

                    if (isset($taken[$booking->id]) || $at->lessThan($made) || ($until && $at->greaterThan($until)) || ! $this->stable("coupon-use:{$row->id}-{$booking->id}", 5)) {
                        continue;
                    }
                    if (isset($restrictions[$row->id]) && ! isset($restrictions[$row->id][$booking->activity_id])) {
                        continue;
                    }
                    if ($row->min_order_amount !== null && (float) $booking->subtotal < (float) $row->min_order_amount) {
                        continue;
                    }

                    $isAlive = ! in_array($booking->status, CouponService::DEAD_BOOKINGS, true);
                    if ($isAlive && $row->usage_limit !== null && ($alive[$row->id] ?? 0) >= $row->usage_limit) {
                        continue;
                    }

                    $discount = $service->discountFor($coupon, (float) $booking->subtotal);
                    if ($discount <= 0) {
                        continue;
                    }

                    DB::table('reservations')->where('id', $booking->id)->update(['discount' => $discount, 'total' => round((float) $booking->subtotal - $discount + (float) $booking->tax, 2)]);
                    DB::table('coupon_redemptions')->insert(['coupon_id' => $row->id, 'reservation_id' => $booking->id, 'customer_id' => $booking->customer_id, 'code' => $row->code, 'discount_amount' => $discount, 'created_at' => $booking->created_at]);

                    $taken[$booking->id] = true;
                    $alive[$row->id] = ($alive[$row->id] ?? 0) + ($isAlive ? 1 : 0);
                    $this->created++;
                }
            }
        }
    }
}
