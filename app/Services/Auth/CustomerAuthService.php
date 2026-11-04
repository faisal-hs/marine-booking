<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Auth\Concerns\RefusesDeletedAccounts;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AccountService;
use App\Services\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerAuthService
{
    use RefusesDeletedAccounts;

    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected CustomerRepositoryInterface $customerRepository,
        protected OtpService $otpService,
        protected AccountService $accounts,
    ) {}

    public function requestOtp(string $phone, ?string $name): void
    {
        $this->refuseDeletedAccount($phone);

        $existingUser = $this->userRepository->findByPhone($phone);

        if ($existingUser) {
            // A provider can use the customer side too (see AccountService); only staff cannot.
            if ($existingUser->isAdmin()) {
                throw ValidationException::withMessages([
                    'phone' => ['This phone number is not registered as a customer.'],
                ]);
            }

            $this->otpService->issue($phone, 'login', $existingUser->id);

            return;
        }

        if (! $name) {
            throw ValidationException::withMessages([
                'name' => ['Name is required to register.'],
            ]);
        }

        $this->otpService->issue($phone, 'registration', null, ['name' => $name]);
    }

    public function resendOtp(string $phone): void
    {
        $this->otpService->resend($phone);
    }

    /**
     * @return array{user: User, customer: \App\Models\Customer, token: string}
     */
    public function verifyOtp(string $phone, string $code): array
    {
        $otp = $this->otpService->verify($phone, $code);

        return DB::transaction(function () use ($otp, $phone) {
            if ($otp->type === 'login') {
                $user = $this->userRepository->findByPhone($phone);

                if (! $user || $user->isAdmin()) {
                    throw ValidationException::withMessages([
                        'phone' => ['Customer account not found.'],
                    ]);
                }

                if ($user->status !== 'active') {
                    throw ValidationException::withMessages([
                        'phone' => ['This account is not active. Please contact support.'],
                    ]);
                }

                $this->accounts->ensureCustomerAccount($user);

                return $this->accounts->session($user, 'customer', $user->createToken('customer-auth', ['customer'])->plainTextToken);
            }

            // A code issued before the account was deleted must not register over it.
            $this->refuseDeletedAccount($phone);

            $payload = $otp->payload;

            $user = $this->userRepository->create([
                'name' => $payload['name'],
                'phone' => $phone,
                'user_type' => 'customer',
                'status' => 'active',
                'phone_verified_at' => now(),
            ]);

            $this->customerRepository->create([
                'user_id' => $user->id,
                'preferred_language' => 'en',
            ]);

            return $this->accounts->session($user, 'customer', $user->createToken('customer-auth', ['customer'])->plainTextToken);
        });
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
