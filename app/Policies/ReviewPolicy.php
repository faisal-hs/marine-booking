<?php

namespace App\Policies;

use App\Models\User;

/**
 * viewAny/view are permission-based (reviews.view — assigned to admin
 * and support_admin), matching ReservationPolicy/PaymentPolicy exactly.
 * moderate() is gated by the SEPARATE, more restrictive
 * 'reviews.moderate' permission (admin only, not support_admin),
 * matching the same view/mutate split already established for
 * payments.view vs payments.refund.
 */
class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reviews.view');
    }

    public function moderate(User $user): bool
    {
        return $user->can('reviews.moderate');
    }
}
