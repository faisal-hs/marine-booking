<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One phone, up to two accounts: a customer account and a provider account.
 *
 * - The CUSTOMER account is always available to anyone who is not staff. It needs no approval, so it is
 *   created the first time it is used (a provider who signs in as a customer, say).
 * - The PROVIDER account is available only once an admin has approved a provider request.
 *
 * users.user_type is the account a person REGISTERED as; it does not say what they can use now. This
 * class is the single place that answers "which accounts can this person use?", "which is their
 * default?" and "which account is this token signed into?". A token carries exactly one of the
 * abilities `customer` or `provider`; switching issues a new token for the other and revokes the old.
 */
class AccountService
{
    public const CUSTOMER = 'customer';

    public const PROVIDER = 'provider';

    public const ACCOUNTS = [self::CUSTOMER, self::PROVIDER];

    public function __construct(protected CustomerRepositoryInterface $customers) {}

    /** The accounts this person can use right now (staff have none: they sign in to the web panel). */
    public function available(User $user): array
    {
        if ($user->isAdmin()) {
            return [];
        }

        $accounts = [self::CUSTOMER];

        if ($user->provider?->isVerified()) {
            $accounts[] = self::PROVIDER;
        }

        return $accounts;
    }

    /**
     * The account the role-agnostic sign-in opens. The person's own choice if it is still usable;
     * otherwise the one they registered as. A provider who has not been approved yet is therefore
     * signed into the provider side, where they can finish or check their request.
     */
    public function defaultAccount(User $user): string
    {
        $available = $this->available($user);

        if ($user->default_account && in_array($user->default_account, $available, true)) {
            return $user->default_account;
        }

        if ($user->user_type === self::PROVIDER) {
            return self::PROVIDER;
        }

        return in_array($user->user_type, $available, true) ? $user->user_type : ($available[0] ?? self::CUSTOMER);
    }

    /** none | not_submitted | pending | approved | rejected */
    public function providerRequestStatus(User $user): string
    {
        $provider = $user->provider;

        if (! $provider) {
            return 'none';
        }

        if ($provider->isVerified()) {
            return 'approved';
        }

        return $provider->latestRequest?->status
            ?? ($provider->verification_status === 'rejected' ? 'rejected' : 'not_submitted');
    }

    /** What the apps need to draw the account switcher. $current is the account the token is signed into. */
    public function context(User $user, string $current): array
    {
        return [
            'account_type' => $current,
            'default_account' => $this->defaultAccount($user),
            'available_accounts' => $this->available($user),
            'provider_request_status' => $this->providerRequestStatus($user),
        ];
    }

    /** The account the access token in use is signed into. */
    public function currentAccount(User $user): string
    {
        $token = $user->currentAccessToken();
        $provider = (bool) $token?->can(self::PROVIDER);
        $customer = (bool) $token?->can(self::CUSTOMER);

        if ($provider && ! $customer) {
            return self::PROVIDER;
        }

        if ($customer && ! $provider) {
            return self::CUSTOMER;
        }

        return $this->defaultAccount($user);
    }

    public function setDefault(User $user, string $account): User
    {
        if (! in_array($account, $this->available($user), true)) {
            throw ValidationException::withMessages(['default_account' => [$this->unavailableMessage($account)]]);
        }

        $user->update(['default_account' => $account]);

        return $user;
    }

    /**
     * Signs the person into the other account: a new token for it, and the token in use is revoked.
     *
     * @return array{user: User, token: string, account: array, customer?: ?Customer, provider?: mixed}
     */
    public function switchTo(User $user, string $target, ?PersonalAccessToken $current = null): array
    {
        if (! in_array($target, self::ACCOUNTS, true) || ! in_array($target, $this->available($user), true)) {
            throw ValidationException::withMessages(['account_type' => [$this->unavailableMessage($target)]]);
        }

        return DB::transaction(function () use ($user, $target, $current) {
            if ($target === self::CUSTOMER) {
                $this->ensureCustomerAccount($user);
            }

            $current?->delete();

            return $this->session($user, $target, $user->createToken("{$target}-auth", [$target])->plainTextToken);
        });
    }

    /** What every sign-in and switch returns: the user, the token for that account, and the account context. */
    public function session(User $user, string $account, string $token): array
    {
        $user->unsetRelation('customer')->unsetRelation('provider');

        return [
            'user' => $user,
            'token' => $token,
            $account => $account === self::CUSTOMER ? $user->customer : $user->provider,
            'account' => $this->context($user, $account),
        ];
    }

    /** The customer account of someone who has none yet, created on first use. No approval is needed. */
    public function ensureCustomerAccount(User $user): Customer
    {
        return $user->customer
            ?? tap($this->customers->create(['user_id' => $user->id, 'preferred_language' => 'en']), fn() => $user->unsetRelation('customer'));
    }

    private function unavailableMessage(string $account): string
    {
        return $account === self::PROVIDER
            ? 'You do not have an approved provider account yet. Submit a provider request and wait for an admin to approve it.'
            : 'That account is not available.';
    }
}
