<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to become a provider: submitted by a customer who wants to start offering activities,
 * or by someone who registered as a provider. Until an admin approves it the account can do nothing
 * on the provider side. The business details and documents live on the Provider the request points
 * at; this row is the submission and its review.
 */
class ProviderRequest extends Model
{
    use HasUuid;

    public const SOURCE_REGISTRATION = 'registration';

    public const SOURCE_CUSTOMER_UPGRADE = 'customer_upgrade';

    protected $fillable = [
        'user_id',
        'provider_id',
        'source',
        'status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
