<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class AdminAuthService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Validates admin credentials and returns the User. The browser panel logs them in
     * through the session guard; the JSON API turns them into a token with issueToken().
     */
    public function attemptLogin(string $email, string $password): User
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['This account is not an admin account.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        return $user;
    }

    /** A bearer token for the JSON API: admin ability only, and it expires (see config/admin_api.php). */
    public function issueToken(User $user): NewAccessToken
    {
        return $user->createToken('admin-api', ['admin'], now()->addHours(config('admin_api.token_hours')));
    }
}
