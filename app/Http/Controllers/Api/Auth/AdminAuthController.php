<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\Admin\LoginRequest;
use App\Services\Auth\AdminAuthService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Staff sign-in for the JSON API. The browser admin panel keeps its session; this gives scripts and other
 * clients a bearer token that works on /api/v1/admin/* (which has no session to rely on).
 */
class AdminAuthController extends BaseApiController
{
    public function __construct(protected AdminAuthService $authService) {}

    public function login(LoginRequest $request)
    {
        $data = $request->payload();

        $token = $this->authService->issueToken($this->authService->attemptLogin($data['email'], $data['password']));
        $user = $token->accessToken->tokenable;

        return $this->success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
            ],
        ], 'Signed in.');
    }

    /** Revokes the token that made the request. */
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return $this->success(null, 'Signed out.');
    }
}
