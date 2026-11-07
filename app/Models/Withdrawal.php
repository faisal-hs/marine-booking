<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A provider's request to be paid out. Reserved when asked, paid by an admin by hand. */
class Withdrawal extends Model
{
    use HasUuid;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::PROCESSING, self::PAID, self::FAILED, self::REJECTED, self::CANCELLED];

    /** Statuses in which the amount is still held back from the provider's available balance. */
    public const RESERVING = [self::PENDING, self::PROCESSING];

    protected $fillable = ['provider_id', 'amount', 'fee', 'net_amount', 'currency', 'status', 'idempotency_key', 'bank_snapshot', 'payout_reference', 'status_reason', 'processed_by_user_id', 'processed_at'];

    protected $casts = ['amount' => 'float', 'fee' => 'float', 'net_amount' => 'float', 'bank_snapshot' => 'encrypted:array', 'processed_at' => 'datetime'];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function isReserving(): bool
    {
        return in_array($this->status, self::RESERVING, true);
    }
}
