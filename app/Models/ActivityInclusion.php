<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ActivityInclusion extends Model
{
    use HasUuid;

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected $fillable = [
        'activity_id',
        'type',
        'description',
        'sort_order',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
