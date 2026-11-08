<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A provider's reply to a review. One is active at a time; a deleted one stays as an audit record. */
class ReviewReply extends Model
{
    protected $fillable = ['review_id', 'provider_id', 'text', 'is_active', 'deleted_at', 'deleted_by_user_id'];

    protected $casts = ['is_active' => 'boolean', 'deleted_at' => 'datetime'];

    public function review()
    {
        return $this->belongsTo(Review::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
