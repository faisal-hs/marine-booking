<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProviderAuthService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected ProviderRepositoryInterface $providerRepository,
    ) {}

    /**
     * @return array{user: User, provider: \App\Models\Provider, token: string}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'user_type' => 'provider',
                'status' => 'active',
            ]);

            $provider = $this->providerRepository->create([
                'user_id' => $user->id,
                'business_name' => $data['business_name'],
                'slug' => $this->generateUniqueSlug($data['business_name']),
            ]);

            $token = $user->createToken('provider-auth', ['provider'])->plainTextToken;

            return ['user' => $user, 'provider' => $provider, 'token' => $token];
        });
    }

    /**
     * @return array{user: User, provider: \App\Models\Provider, token: string}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isProvider()) {
            throw ValidationException::withMessages([
                'email' => ['This account is not registered as a provider.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account is not active. Please contact support.'],
            ]);
        }

        $token = $user->createToken('provider-auth', ['provider'])->plainTextToken;

        return ['user' => $user, 'provider' => $user->provider, 'token' => $token];
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
