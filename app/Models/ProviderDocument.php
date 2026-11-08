<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProviderDocument extends Model
{
    use HasUuid;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'provider_id',
        'type',
        'title',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'status',
        'review_note',
        'reviewed_by_user_id',
        'reviewed_at',
        'expires_at',
        'superseded_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'reviewed_at' => 'datetime',
        'expires_at' => 'date',
        'superseded_at' => 'datetime',
    ];

    /** The version in force: one that no newer upload has replaced. */
    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_at');
    }

    /** Approved, but past the date the admin gave it. */
    public function isExpired(): bool
    {
        return $this->status === self::APPROVED && $this->expires_at !== null && $this->expires_at->isPast() && ! $this->expires_at->isToday();
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function events()
    {
        return $this->hasMany(ProviderDocumentEvent::class)->oldest()->oldest('id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
