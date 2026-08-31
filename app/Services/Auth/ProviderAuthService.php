<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProviderAuthService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected ProviderRepositoryInterface $providerRepository,
        protected OtpService $otpService,
    ) {}

    /**
     * Requests an OTP for either a new provider registration or an
     * existing provider login, determined by whether the phone is known.
     */
    public function requestOtp(string $phone, ?string $ownerName, ?string $businessName): void
    {
        $existingUser = $this->userRepository->findByPhone($phone);

        if ($existingUser) {
            if (! $existingUser->isProvider()) {
                throw ValidationException::withMessages([
                    'phone' => ['This phone number is not registered as a provider.'],
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
     * @return array{user: User, provider: \App\Models\Provider, token: string}
     */
    public function verifyOtp(string $phone, string $code): array
    {
        $otp = $this->otpService->verify($phone, $code);

        return DB::transaction(function () use ($otp, $phone) {
            if ($otp->type === 'login') {
                $user = $this->userRepository->findByPhone($phone);

                if (! $user || ! $user->isProvider()) {
                    throw ValidationException::withMessages([
                        'phone' => ['Provider account not found.'],
                    ]);
                }

                if ($user->status !== 'active') {
                    throw ValidationException::withMessages([
                        'phone' => ['This account is not active. Please contact support.'],
                    ]);
                }

                $token = $user->createToken('provider-auth', ['provider'])->plainTextToken;

                return ['user' => $user, 'provider' => $user->provider, 'token' => $token];
            }

            // Registration
            $payload = $otp->payload;

            $user = $this->userRepository->create([
                'name' => $payload['owner_name'],
                'phone' => $phone,
                'user_type' => 'provider',
                'status' => 'active',
                'phone_verified_at' => now(),
            ]);

            $provider = $this->providerRepository->create([
                'user_id' => $user->id,
                'business_name' => $payload['business_name'],
                'slug' => $this->generateUniqueSlug($payload['business_name']),
            ]);

            $token = $user->createToken('provider-auth', ['provider'])->plainTextToken;

            return ['user' => $user, 'provider' => $provider, 'token' => $token];
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
