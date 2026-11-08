<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A coupon used on a booking. */
class CouponRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['coupon_id', 'reservation_id', 'customer_id', 'code', 'discount_amount', 'created_at'];

    protected $casts = ['discount_amount' => 'float', 'created_at' => 'datetime'];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
