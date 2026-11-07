<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Withdrawal;

class WithdrawalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('withdrawals.view');
    }

    public function view(User $user, Withdrawal $withdrawal): bool
    {
        return $user->can('withdrawals.view');
    }

    /** Paying out, rejecting and the rest: whoever moves the money. */
    public function process(User $user, Withdrawal $withdrawal): bool
    {
        return $user->can('withdrawals.process');
    }
}
