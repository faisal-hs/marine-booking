<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user || ! $user->isAdmin() || $user->status !== 'active') {
            Auth::guard('web')->logout();

            return redirect()->route('admin.login')
                ->withErrors(['email' => 'Unauthorized access.']);
        }

        return $next($request);
    }
}
