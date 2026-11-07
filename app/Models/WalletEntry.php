<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** One signed row of a provider's wallet ledger. Never edited: a correction is a new row. */
class WalletEntry extends Model
{
    use HasUuid;

    public const BOOKING_INCOME = 'booking_income';

    public const PLATFORM_FEE = 'platform_fee';

    public const REFUND = 'refund';

    public const WITHDRAWAL_RESERVE = 'withdrawal_reserve';

    public const WITHDRAWAL_RELEASE = 'withdrawal_release';

    public const ADJUSTMENT = 'adjustment';

    /** The types that come from bookings (they wait for the settlement period). */
    public const BOOKING_TYPES = [self::BOOKING_INCOME, self::PLATFORM_FEE, self::REFUND];

    protected $fillable = ['provider_id', 'reservation_id', 'withdrawal_id', 'type', 'amount', 'currency', 'available_at', 'occurred_on', 'description', 'reference', 'meta'];

    protected $casts = ['amount' => 'float', 'available_at' => 'datetime', 'occurred_on' => 'date', 'meta' => 'array'];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
