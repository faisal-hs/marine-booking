<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * viewAny/view are pure permission checks (no ownership check, since an
 * admin with 'payments.view' legitimately sees every payment), matching
 * ReservationPolicy exactly. refund() is gated by a SEPARATE, more
 * restrictive permission ('payments.refund') — matching this codebase's
 * own established convention of splitting view from mutate permissions
 * (reservations.view vs reservations.update/cancel). Currently only
 * super_admin and admin have payments.refund; support_admin does not.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view');
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->can('payments.refund');
    }
}
