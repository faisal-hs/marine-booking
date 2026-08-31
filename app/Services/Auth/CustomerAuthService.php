<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerAuthService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected CustomerRepositoryInterface $customerRepository,
        protected OtpService $otpService,
    ) {}

    public function requestOtp(string $phone, ?string $name): void
    {
        $existingUser = $this->userRepository->findByPhone($phone);

        if ($existingUser) {
            if (! $existingUser->isCustomer()) {
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
        if (! $this->otpService->canResend($phone)) {
            throw ValidationException::withMessages([
                'phone' => ['Please wait before requesting another code.'],
            ]);
        }

        $last = $this->otpService->latest($phone);

        if (! $last) {
            throw ValidationException::withMessages([
                'phone' => ['No previous verification request found for this phone.'],
            ]);
        }

        $this->otpService->issue($phone, $last->type, $last->user_id, $last->payload);
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

                if (! $user || ! $user->isCustomer()) {
                    throw ValidationException::withMessages([
                        'phone' => ['Customer account not found.'],
                    ]);
                }

                if ($user->status !== 'active') {
                    throw ValidationException::withMessages([
                        'phone' => ['This account is not active. Please contact support.'],
                    ]);
                }

                $token = $user->createToken('customer-auth', ['customer'])->plainTextToken;

                return ['user' => $user, 'customer' => $user->customer, 'token' => $token];
            }

            $payload = $otp->payload;

            $user = $this->userRepository->create([
                'name' => $payload['name'],
                'phone' => $phone,
                'user_type' => 'customer',
                'status' => 'active',
                'phone_verified_at' => now(),
            ]);

            $customer = $this->customerRepository->create([
                'user_id' => $user->id,
                'preferred_language' => 'en',
            ]);

            $token = $user->createToken('customer-auth', ['customer'])->plainTextToken;

            return ['user' => $user, 'customer' => $customer, 'token' => $token];
        });
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
