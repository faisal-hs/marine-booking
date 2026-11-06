<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin JSON API is for active staff, with an admin token. A customer or provider token is refused even
 * though it is a perfectly valid Sanctum token, and so is a staff account that has been suspended since the token was
 * issued. (A browser session has no token and passes the token check, which is what the tests use.)
 */
class EnsureStaffApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin() || $user->status !== 'active' || ! $user->tokenCan('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'This area is for staff accounts only.',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
