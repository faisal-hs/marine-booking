<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A provider's coupon: a percentage or a fixed amount off a booking, funded by the provider. */
class Coupon extends Model
{
    use HasUuid;

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    protected $fillable = ['provider_id', 'code', 'type', 'value', 'usage_limit', 'max_discount', 'min_order_amount', 'expires_at', 'is_active'];

    protected $casts = ['value' => 'float', 'usage_limit' => 'integer', 'max_discount' => 'float', 'min_order_amount' => 'float', 'expires_at' => 'datetime', 'is_active' => 'boolean'];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    /** The activities it is limited to; none listed means every activity of the provider. */
    public function activities()
    {
        return $this->belongsToMany(Activity::class, 'activity_coupon');
    }

    public function redemptions()
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
