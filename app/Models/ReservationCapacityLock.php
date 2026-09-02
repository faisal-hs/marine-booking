<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservationCapacityLock extends Model
{
    protected $fillable = [
        'activity_booking_option_id',
        'schedule_period_id',
        'reservation_date',
    ];

    protected $casts = [
        'reservation_date' => 'date:Y-m-d',
    ];
}
