<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Completed = 'completed';

    /**
     * Statuses that still temporarily consume capacity. Pending only
     * counts while its hold hasn't expired yet — that time-based check
     * happens in the repository query, not here (this enum has no
     * awareness of a specific reservation's hold_expires_at).
     */
    public function blocksCapacityWhenActive(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }
}
