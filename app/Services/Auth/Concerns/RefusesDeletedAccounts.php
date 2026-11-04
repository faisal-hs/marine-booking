<?php

namespace App\Services\Auth\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * A deleted account is soft-deleted, so it still holds its phone number. Without this
 * check, someone whose account was deleted who tried to sign in again was treated as a
 * brand-new visitor, and registration then collided with the phone the deleted row still
 * owns: a raw SQL "unique constraint" error and an HTTP 500. It is refused cleanly
 * instead, before any one-time code is sent. Restoring the account lets them in again.
 *
 * Expects the using class to have a $userRepository (UserRepositoryInterface).
 */
trait RefusesDeletedAccounts
{
    protected function refuseDeletedAccount(string $phone): void
    {
        $account = $this->userRepository->findByPhoneIncludingDeleted($phone);

        if ($account !== null && $account->trashed()) {
            throw ValidationException::withMessages([
                'phone' => ['This account has been deleted. Please contact support.'],
            ]);
        }
    }
}
