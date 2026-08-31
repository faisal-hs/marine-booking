<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ActivityOption extends Model
{
    use HasUuid;

    protected $attributes = [
        'extra_price' => 0,
        'is_required' => false,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'activity_id',
        'name',
        'description',
        'extra_price',
        'is_required',
        'max_quantity',
        'sort_order',
    ];

    protected $casts = [
        'extra_price' => 'decimal:2',
        'is_required' => 'boolean',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
