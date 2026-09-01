<?php

namespace App\Models;

use App\Enums\ParticipantType;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class ActivityPricingRule extends Model
{
    use HasUuid;

    protected $attributes = [
        'currency' => 'EGP',
        'is_active' => true,
    ];

    protected $fillable = [
        'activity_booking_option_id',
        'participant_type',
        'price',
        'min_quantity',
        'max_quantity',
        'schedule_period_id',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'participant_type' => ParticipantType::class,
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function bookingOption()
    {
        return $this->belongsTo(ActivityBookingOption::class, 'activity_booking_option_id');
    }

    public function schedulePeriod()
    {
        return $this->belongsTo(ActivitySchedulePeriod::class, 'schedule_period_id');
    }
}
