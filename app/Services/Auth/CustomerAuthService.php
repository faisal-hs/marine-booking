<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomerAuthService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected CustomerRepositoryInterface $customerRepository,
    ) {}

    /**
     * @return array{user: User, customer: \App\Models\Customer, token: string}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'user_type' => 'customer',
                'status' => 'active',
            ]);

            $customer = $this->customerRepository->create([
                'user_id' => $user->id,
                'preferred_language' => $data['preferred_language'] ?? 'en',
            ]);

            $token = $user->createToken('customer-auth', ['customer'])->plainTextToken;

            return ['user' => $user, 'customer' => $customer, 'token' => $token];
        });
    }

    /**
     * @return array{user: User, customer: \App\Models\Customer, token: string}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isCustomer()) {
            throw ValidationException::withMessages([
                'email' => ['This account is not registered as a customer.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account is not active. Please contact support.'],
            ]);
        }

        $token = $user->createToken('customer-auth', ['customer'])->plainTextToken;

        return ['user' => $user, 'customer' => $user->customer, 'token' => $token];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
