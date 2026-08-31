<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'min_participants' => 1,
    ];

    protected $fillable = [
        'provider_id',
        'category_id',
        'title',
        'slug',
        'description',
        'location',
        'meeting_point',
        'lat',
        'lng',
        'duration_minutes',
        'min_participants',
        'max_participants',
        'min_age',
        'status',
        'rejection_reason',
        'published_at',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'published_at' => 'datetime',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
