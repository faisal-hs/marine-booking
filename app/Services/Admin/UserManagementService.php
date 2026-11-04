<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * STAFF accounts only (user_type "admin").
 *
 * Providers and customers each have their own module (ProviderManagementService,
 * CustomerManagementService) because their accounts carry business rules a generic
 * user form cannot respect: a provider has activities that must be taken off the
 * public site when the account is suspended or deleted, a customer may have
 * upcoming bookings, and neither can be deleted while a booking is still live. This
 * screen used to create, edit (even a provider's verification status, bypassing the
 * verify flow and its notification) and delete all three kinds, which skipped every
 * one of those rules.
 */
class UserManagementService
{
    /** Roles a staff account can hold. The seeded "provider" and "customer" roles are not staff. */
    public const STAFF_ROLES = ['super_admin', 'admin', 'support_admin', 'content_admin'];

    public function __construct(protected UserRepositoryInterface $userRepository) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->userRepository->paginateWithFilters(array_merge($filters, ['user_type' => 'admin']));
    }

    /**
     * The roles $actor may hand out. Only a super admin can create or promote a super
     * admin; otherwise anyone holding users.create / users.update could make themselves
     * (or a friend) all-powerful.
     *
     * @return string[]
     */
    public function assignableRoles(User $actor): array
    {
        $roles = $actor->hasRole('super_admin')
            ? self::STAFF_ROLES
            : array_values(array_diff(self::STAFF_ROLES, ['super_admin']));

        return Role::whereIn('name', $roles)->orderBy('id')->pluck('name')->all();
    }

    /** Admin-created staff account, bypassing OTP: staff sign in to the panel with a password. */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'user_type' => 'admin',
                'status' => $data['status'] ?? 'active',
                'email_verified_at' => now(),
            ]);

            Admin::create(['user_id' => $user->id, 'job_title' => $data['job_title'] ?? null]);

            if (! empty($data['role'])) {
                $user->syncRoles([$data['role']]);
            }

            return $user->fresh();
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $statusChanging = isset($data['status']) && $data['status'] !== $user->status;
            $roleChanging = array_key_exists('role', $data) && $data['role'] !== null && ! $user->hasRole($data['role']);

            // Locking everyone out is the one mistake this screen must not allow.
            if (($statusChanging && $data['status'] !== 'active') || ($roleChanging && $user->hasRole('super_admin'))) {
                $this->assertNotLastSuperAdmin($user);
            }

            $user->update(array_filter([
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'] ?? null,
            ], fn ($v) => $v !== null));

            // Anything other than 'active' revokes API access at once rather than leaving an
            // already-issued token usable until it expires.
            if ($statusChanging && $data['status'] !== 'active') {
                $user->tokens()->delete();
            }

            if (! empty($data['role'])) {
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

        if ($status !== 'active') {
            $this->assertNotLastSuperAdmin($user);
        }

        $user->update(['status' => $status]);

        if ($status !== 'active') {
            $user->tokens()->delete();
        }

        return $user->fresh();
    }

    public function delete(User $user): void
    {
        $this->assertNotLastSuperAdmin($user);

        $user->tokens()->delete();
        $user->delete(); // soft delete, per §22 — never hard-delete user records
    }

    private function assertNotLastSuperAdmin(User $user): void
    {
        if (! $user->hasRole('super_admin')) {
            return;
        }

        $otherActive = User::role('super_admin')
            ->where('status', 'active')
            ->where('id', '!=', $user->id)
            ->exists();

        if (! $otherActive) {
            throw ValidationException::withMessages([
                'user' => ['This is the last active super admin. Create or activate another one first, so the panel cannot be locked out.'],
            ]);
        }
    }
}
