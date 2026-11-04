<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * An announcement an admin sends to a whole audience (every customer, every provider) or to
 * one account. Each recipient gets an ordinary in-app notification plus a push, delivered
 * through the same queued, idempotent, failure-isolated path as every other notification.
 */
class Broadcast extends Model
{
    use HasUuid;

    protected $fillable = [
        'title',
        'body',
        'audience',
        'recipient_user_id',
        'status',
        'recipients_count',
        'delivered_count',
        'created_by',
        'sent_at',
        'failed_reason',
    ];

    protected $casts = [
        'recipients_count' => 'integer',
        'delivered_count' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id')->withTrashed();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
