<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One visitor looking at one activity on one day. */
class ActivityView extends Model
{
    public const UPDATED_AT = null;

    public const SOURCES = ['direct', 'category', 'recommendations', 'social'];

    protected $fillable = ['activity_id', 'visitor_hash', 'viewed_on', 'source', 'created_at'];

    protected $casts = ['viewed_on' => 'date', 'created_at' => 'datetime'];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
