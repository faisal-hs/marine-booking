<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ActivitySchedulePeriod extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
        'crosses_midnight' => false,
    ];

    protected $fillable = [
        'activity_id',
        'day_of_week',
        'start_time',
        'end_time',
        'crosses_midnight',
        'price',
        'instructions',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'crosses_midnight' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * A period "crosses midnight" when its end_time is earlier in the day
     * than its start_time (e.g. 17:00 -> 05:00). Time columns come back
     * from the DB as "H:i:s" strings, not Carbon instances, so this is a
     * plain string comparison on zero-padded 24h time — safe because both
     * values share the same format.
     */
    public static function determinesCrossesMidnight(string $startTime, string $endTime): bool
    {
        return $startTime > $endTime;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
