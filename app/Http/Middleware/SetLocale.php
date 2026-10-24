<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts an optional area parameter — middleware('locale') (no
 * parameter) keeps existing admin behavior exactly as before, reading
 * the 'admin_locale' session key; middleware('locale:public') reads a
 * SEPARATE 'public_locale' session key instead. Keeping these two areas'
 * locale preferences independent means a customer browsing the public
 * site in Arabic never affects (or is affected by) an admin's own
 * locale choice, even within the same browser session.
 */
class SetLocale
{
    /** The languages the application is translated into (lang/en, lang/ar). */
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next, string $area = 'admin'): Response
    {
        $sessionKey = $area === 'public' ? 'public_locale' : 'admin_locale';

        $locale = $request->session()->get($sessionKey, 'en');

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'en';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
