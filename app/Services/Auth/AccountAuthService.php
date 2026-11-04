<?php

namespace App\Services\Auth;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AccountService;
use App\Services\Auth\Concerns\RefusesDeletedAccounts;
use App\Services\OtpService;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in that does not ask which kind of account: for someone who already has one. The code opens
 * the account the person chose as their default (see AccountService::defaultAccount), and the
 * response says which accounts they can switch to.
 *
 * Registering a NEW phone still goes through the customer or provider endpoints, because those need
 * different details (a name; an owner name and a business name).
 */
class AccountAuthService
{
    use RefusesDeletedAccounts;

    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected OtpService $otpService,
        protected AccountService $accounts,
    ) {}

    public function requestOtp(string $phone): void
    {
        $this->refuseDeletedAccount($phone);

        $user = $this->userRepository->findByPhone($phone);

        if (! $user || $user->isAdmin()) {
            throw ValidationException::withMessages([
                'phone' => ['No account found for this phone number. Register as a customer or as a provider first.'],
            ]);
        }

        $this->otpService->issue($phone, 'login', $user->id);
    }

    public function resendOtp(string $phone): void
    {
        $this->otpService->resend($phone);
    }

    public function verifyOtp(string $phone, string $code): array
    {
        $otp = $this->otpService->verify($phone, $code);

        // A registration code belongs to the customer or provider sign-up that issued it.
        $user = $otp->type === 'login' ? $this->userRepository->findByPhone($phone) : null;

        if (! $user || $user->isAdmin()) {
            throw ValidationException::withMessages([
                'phone' => ['No account found for this phone number. Register as a customer or as a provider first.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'phone' => ['This account is not active. Please contact support.'],
            ]);
        }

        $account = $this->accounts->defaultAccount($user);

        if ($account === AccountService::CUSTOMER) {
            $this->accounts->ensureCustomerAccount($user);
        }

        return $this->accounts->session($user, $account, $user->createToken("{$account}-auth", [$account])->plainTextToken);
    }
}
