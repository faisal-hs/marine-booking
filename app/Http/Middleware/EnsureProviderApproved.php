<?php

namespace App\Http\Middleware;

use App\Services\AccountService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until an admin approves their provider request, a provider account can do nothing on the provider
 * side: no activities, no bookings, no documents, no notifications. The only things open to it are
 * reading its own profile and submitting or checking its request (both under /account).
 */
class EnsureProviderApproved
{
    public function __construct(protected AccountService $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->provider?->isVerified()) {
            $status = $user ? $this->accounts->providerRequestStatus($user) : 'none';

            // The provider app answers in the spec's shape, with the spec's code.
            if (\App\Support\ProviderApi\ProviderEnvelope::applies($request)) {
                return \App\Support\ProviderApi\ProviderEnvelope::error(
                    'Your provider account is not approved yet. Submit your provider request and wait for an admin to approve it.',
                    403,
                    \App\Support\ProviderApi\ErrorCode::PROVIDER_NOT_APPROVED,
                    null,
                    ['provider_request_status' => $status]
                );
            }

            return response()->json([
                'success' => false,
                'message' => 'Your provider account is not approved yet. Submit your provider request and wait for an admin to approve it.',
                'data' => ['provider_request_status' => $user ? $this->accounts->providerRequestStatus($user) : 'none'],
            ], 403);
        }

        return $next($request);
    }
}
