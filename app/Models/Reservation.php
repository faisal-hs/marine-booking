<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'status' => 'pending',
        'discount' => 0,
        'tax' => 0,
    ];

    protected $fillable = [
        'activity_id',
        'activity_booking_option_id',
        'customer_id',
        'schedule_period_id',
        'reservation_date',
        'participants',
        'quantity',
        'duration',
        'status',
        'hold_expires_at',
        'pricing_model',
        'currency',
        'subtotal',
        'discount',
        'tax',
        'total',
        'pricing_breakdown',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'reservation_date' => 'date:Y-m-d',
        'participants' => 'array',
        'pricing_breakdown' => 'array',
        'status' => ReservationStatus::class,
        'hold_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function bookingOption()
    {
        return $this->belongsTo(ActivityBookingOption::class, 'activity_booking_option_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function schedulePeriod()
    {
        return $this->belongsTo(ActivitySchedulePeriod::class, 'schedule_period_id');
    }

    /**
     * True only while a pending hold genuinely still counts against
     * capacity — a pending row past its hold_expires_at is functionally
     * inert even before any scheduled job flips it to 'expired'.
     */
    public function isActivelyHoldingCapacity(): bool
    {
        if ($this->status === ReservationStatus::Confirmed) {
            return true;
        }

        return $this->status === ReservationStatus::Pending
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isFuture();
    }
}
