<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected ProviderRepositoryInterface $providerRepository,
        protected CustomerRepositoryInterface $customerRepository,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->userRepository->paginateWithFilters($filters);
    }

    /**
     * Admin-created account, bypassing OTP/self-registration. Only 'admin'
     * accounts require a password here; provider/customer created this way
     * remain passwordless, consistent with Module 01's auth model — an
     * admin-created provider/customer simply has no way to log in until
     * they complete OTP verification with their assigned phone number.
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => isset($data['password']) ? Hash::make($data['password']) : null,
                'user_type' => $data['user_type'],
                'status' => $data['status'] ?? 'active',
                'email_verified_at' => $data['user_type'] === 'admin' ? now() : null,
            ]);

            match ($data['user_type']) {
                'admin' => Admin::create(['user_id' => $user->id, 'job_title' => $data['job_title'] ?? null]),
                'provider' => $this->providerRepository->create([
                    'user_id' => $user->id,
                    'business_name' => $data['business_name'],
                    'slug' => $this->generateUniqueSlug($data['business_name']),
                ]),
                'customer' => $this->customerRepository->create([
                    'user_id' => $user->id,
                    'preferred_language' => $data['preferred_language'] ?? 'en',
                ]),
            };

            if ($data['user_type'] === 'admin' && ! empty($data['role'])) {
                $user->syncRoles([$data['role']]);
            }

            return $user->fresh();
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $statusChanging = isset($data['status']) && $data['status'] !== $user->status;

            $user->update(array_filter([
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'] ?? null,
            ], fn($v) => $v !== null));

            // A status change to anything other than 'active' immediately revokes
            // API access — a suspended provider/customer shouldn't keep using an
            // already-issued token until it naturally expires or they try to log in again.
            if ($statusChanging && $data['status'] !== 'active') {
                $user->tokens()->delete();
            }

            if ($user->isProvider() && $user->provider) {
                $user->provider->update(array_filter([
                    'business_name' => $data['business_name'] ?? null,
                    'verification_status' => $data['verification_status'] ?? null,
                ], fn($v) => $v !== null));
            }

            if ($user->isCustomer() && $user->customer) {
                $user->customer->update(array_filter([
                    'preferred_language' => $data['preferred_language'] ?? null,
                ], fn($v) => $v !== null));
            }

            if ($user->isAdmin() && ! empty($data['role'])) {
                $user->syncRoles([$data['role']]);
            }

            return $user->fresh();
        });
    }

    public function setStatus(User $user, string $status): User
    {
        if (! in_array($status, ['active', 'inactive', 'suspended'], true)) {
            throw ValidationException::withMessages(['status' => ['Invalid status.']]);
        }

        $user->update(['status' => $status]);

        // Revoke all API tokens on suspension — a suspended provider/customer
        // should lose access immediately, not just be unable to log in again.
        if ($status !== 'active') {
            $user->tokens()->delete();
        }

        return $user->fresh();
    }

    public function delete(User $user): void
    {
        $user->tokens()->delete();
        $user->delete(); // soft delete, per §22 — never hard-delete user records
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
