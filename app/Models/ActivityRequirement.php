<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ActivityRequirement extends Model
{
    use HasUuid;

    protected $attributes = [
        'is_mandatory' => true,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'activity_id',
        'text',
        'is_mandatory',
        'sort_order',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
