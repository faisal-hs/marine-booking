<?php

namespace App\Models;

use App\Enums\BookingModel;
use App\Models\ActivityPricingRule;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ActivityBookingOption extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'activity_id',
        'name',
        'slug',
        'description',
        'booking_model',
        'min_participants',
        'max_participants',
        'min_quantity',
        'max_quantity',
        'min_duration',
        'max_duration',
        'duration_unit',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'booking_model' => BookingModel::class,
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function pricingRules()
    {
        return $this->hasMany(ActivityPricingRule::class);
    }

    public function activePricingRules()
    {
        return $this->pricingRules()->where('is_active', true);
    }
}
