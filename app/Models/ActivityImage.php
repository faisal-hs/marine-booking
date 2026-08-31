<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ActivityImage extends Model
{
    use HasUuid;

    protected $fillable = [
        'activity_id',
        'path',
        'sort_order',
        'is_cover',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
