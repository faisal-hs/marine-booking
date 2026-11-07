<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProviderProfileChange extends Model
{
    use HasUuid;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['provider_id', 'requested_by_user_id', 'requested_changes', 'status', 'rejection_reason', 'reviewed_by_user_id', 'reviewed_at'];

    protected $casts = ['requested_changes' => 'array', 'reviewed_at' => 'datetime'];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** @return list<string> the fields this request is about */
    public function fields(): array
    {
        return array_keys($this->requested_changes);
    }
}
