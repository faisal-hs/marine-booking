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
     * Canonical day-name mapping, matching the stored 0=Sunday..6=Saturday
     * convention (Carbon::dayOfWeek / PHP date('w')) used everywhere else
     * in this module (AvailabilityService, seeders, etc.). The API accepts
     * and returns day names ('saturday', 'sunday', ...) rather than raw
     * integers — these two helpers are the single place that conversion
     * happens, so Controllers/Requests/Resources never hardcode the map.
     */
    public static function dayNames(): array
    {
        return [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday'];
    }

    public static function dayNumberFromName(string $name): ?int
    {
        $flipped = array_flip(self::dayNames());

        return $flipped[strtolower($name)] ?? null;
    }

    public static function dayNameFromNumber(int $number): ?string
    {
        return self::dayNames()[$number] ?? null;
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
