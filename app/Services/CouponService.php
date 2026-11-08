<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Coupons. The provider that makes one funds it: the customer pays less, and the provider earns on what the customer paid. One coupon per booking.
 *
 * A coupon can be used on a booking when it is active, has not expired, is for that activity (no activities listed means all of the provider's),
 * the booking is large enough, and it has uses left. Its USED COUNT is the bookings it is on that are still alive (not cancelled, not expired):
 * a use is given back when the booking is not kept.
 *
 * The discount is a percentage of the booking (never more than max_discount) or a fixed amount, never more than the booking, and never so much that
 * the booking costs less than config('coupons.minimum_total').
 */
class CouponService
{
    public const DEAD_BOOKINGS = ['cancelled', 'expired'];

    /** Capitals, no spaces: how a code is stored and looked up, so SUMMER20 and summer20 are the same. */
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** inactive (switched off), expired (past its date) or active. */
    public function status(Coupon $coupon): string
    {
        return match (true) {
            ! $coupon->is_active => 'inactive',
            $coupon->isExpired() => 'expired',
            default => 'active',
        };
    }

    public function usedCount(Coupon $coupon): int
    {
        return $coupon->redemptions()->whereHas('reservation', fn ($q) => $q->whereNotIn('status', self::DEAD_BOOKINGS))->count();
    }

    /** Coupons with their used count worked out in the same query (no query per coupon). */
    public function withUsedCount(Builder $query): Builder
    {
        return $query->withCount(['redemptions as used_count' => fn ($q) => $q->whereHas('reservation', fn ($r) => $r->whereNotIn('status', self::DEAD_BOOKINGS))]);
    }

    /**
     * The coupon for this code on this activity, and what it takes off a booking of $subtotal. Refuses with the reason (on `coupon_code`).
     * With $lock the coupon row is locked, so two bookings cannot take the last use at the same moment.
     *
     * @return array{coupon: Coupon, discount: float}
     */
    public function resolve(Activity $activity, string $code, float $subtotal, bool $lock = false): array
    {
        $query = Coupon::query()->where('provider_id', $activity->provider_id)->where('code', self::normalize($code))->with('activities:id');
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();

        // An unknown code and a switched-off one are answered alike: nothing tells you which codes exist.
        if (! $coupon || ! $coupon->is_active) {
            $this->refuse('This coupon code is not valid.');
        }

        if ($coupon->isExpired()) {
            $this->refuse('This coupon has expired.');
        }

        if ($coupon->activities->isNotEmpty() && ! $coupon->activities->contains('id', $activity->id)) {
            $this->refuse('This coupon cannot be used for this activity.');
        }

        if ($coupon->min_order_amount !== null && $subtotal < $coupon->min_order_amount) {
            $this->refuse('This coupon needs a booking of at least '.number_format($coupon->min_order_amount, 2).' '.config('platform.currency').'.');
        }

        if ($coupon->usage_limit !== null && $this->usedCount($coupon) >= $coupon->usage_limit) {
            $this->refuse('This coupon has been used up.');
        }

        $discount = $this->discountFor($coupon, $subtotal);

        if ($discount <= 0) {
            $this->refuse('This coupon cannot be applied to a booking this small.');
        }

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    public function discountFor(Coupon $coupon, float $subtotal): float
    {
        $discount = $coupon->type === Coupon::PERCENT ? round($subtotal * $coupon->value / 100, 2) : $coupon->value;

        if ($coupon->type === Coupon::PERCENT && $coupon->max_discount !== null) {
            $discount = min($discount, $coupon->max_discount);
        }

        // Never more than the booking, and the booking never costs less than the minimum.
        return round(max(0, min($discount, $subtotal - (float) config('coupons.minimum_total'))), 2);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['coupon_code' => [$message]]);
    }
}
