<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** The language of an API answer (the names of cities, the static pages, notification titles): Arabic when the app asks for it, English otherwise. */
class SetApiLocale
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale(str_starts_with(strtolower((string) $request->header('Accept-Language', '')), 'ar') ? 'ar' : 'en');

        return $next($request);
    }
}
