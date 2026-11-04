<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Auth\Concerns\RefusesDeletedAccounts;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AccountService;
use App\Services\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProviderAuthService
{
    use RefusesDeletedAccounts;

    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected ProviderRepositoryInterface $providerRepository,
        protected OtpService $otpService,
        protected AccountService $accounts,
    ) {}

    /**
     * Requests an OTP for either a new provider registration or an
     * existing provider login, determined by whether the phone is known.
     */
    public function requestOtp(string $phone, ?string $ownerName, ?string $businessName): void
    {
        $this->refuseDeletedAccount($phone);

        $existingUser = $this->userRepository->findByPhone($phone);

        if ($existingUser) {
            // A customer has no provider account until an admin approves their request: they
            // become a provider by submitting one from the customer side (see ProviderRequestService).
            if (! $existingUser->provider) {
                throw ValidationException::withMessages([
                    'phone' => ['This phone number has no provider account. Sign in as a customer and submit a provider request.'],
                ]);
            }

            $this->otpService->issue($phone, 'login', $existingUser->id);

            return;
        }

        if (! $ownerName || ! $businessName) {
            throw ValidationException::withMessages([
                'owner_name' => ['Owner name and business name are required to register.'],
                'business_name' => ['Owner name and business name are required to register.'],
            ]);
        }

        $this->otpService->issue($phone, 'registration', null, [
            'owner_name' => $ownerName,
            'business_name' => $businessName,
        ]);
    }

    public function resendOtp(string $phone): void
    {
        $this->otpService->resend($phone);
    }

    /**
     * @return array{user: User, provider: \App\Models\Provider, token: string}
     */
    public function verifyOtp(string $phone, string $code): array
    {
        $otp = $this->otpService->verify($phone, $code);

        return DB::transaction(function () use ($otp, $phone) {
            if ($otp->type === 'login') {
                $user = $this->userRepository->findByPhone($phone);

                if (! $user || ! $user->provider) {
                    throw ValidationException::withMessages([
                        'phone' => ['Provider account not found.'],
                    ]);
                }

                if ($user->status !== 'active') {
                    throw ValidationException::withMessages([
                        'phone' => ['This account is not active. Please contact support.'],
                    ]);
                }

                return $this->accounts->session($user, 'provider', $user->createToken('provider-auth', ['provider'])->plainTextToken);
            }

            // Registration. A code issued before the account was deleted must not register over it.
            $this->refuseDeletedAccount($phone);

            $payload = $otp->payload;

            $user = $this->userRepository->create([
                'name' => $payload['owner_name'],
                'phone' => $phone,
                'user_type' => 'provider',
                'status' => 'active',
                'phone_verified_at' => now(),
            ]);

            $this->providerRepository->create([
                'user_id' => $user->id,
                'business_name' => $payload['business_name'],
                'slug' => $this->generateUniqueSlug($payload['business_name']),
            ]);

            return $this->accounts->session($user, 'provider', $user->createToken('provider-auth', ['provider'])->plainTextToken);
        });
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    protected function generateUniqueSlug(string $businessName): string
    {
        $base = Str::slug($businessName);
        $slug = $base;
        $suffix = 1;

        while ($this->providerRepository->slugExists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
