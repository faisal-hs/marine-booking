<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ActivityAvailabilityOverride extends Model
{
    use HasUuid;

    protected $attributes = [
        'is_unavailable' => false,
        'status' => 'active',
    ];

    protected $fillable = [
        'activity_id',
        'date_from',
        'date_to',
        'is_unavailable',
        'price',
        'instructions',
        'status',
    ];

    protected $casts = [
        'date_from' => 'date:Y-m-d',
        'date_to' => 'date:Y-m-d',
        'is_unavailable' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function coversDate(\Illuminate\Support\Carbon $date): bool
    {
        return $date->between($this->date_from, $this->date_to);
    }
}
