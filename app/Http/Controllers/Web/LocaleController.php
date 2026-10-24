<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Remembers the visitor's language in the session and sends them back to the
 * page they came from. The admin panel and the public website keep SEPARATE
 * session keys (admin_locale / public_locale) so each remembers its own
 * language; SetLocale reads them back on every request.
 *
 * An unsupported language is ignored rather than rejected: the visitor simply
 * lands back on the same page in the language they already had.
 */
class LocaleController extends Controller
{
    public function switchPublic(Request $request, string $locale): RedirectResponse
    {
        return $this->remember($request, 'public_locale', $locale);
    }

    public function switchAdmin(Request $request, string $locale): RedirectResponse
    {
        return $this->remember($request, 'admin_locale', $locale);
    }

    private function remember(Request $request, string $sessionKey, string $locale): RedirectResponse
    {
        if (in_array($locale, SetLocale::SUPPORTED, true)) {
            $request->session()->put($sessionKey, $locale);
        }

        return back();
    }
}
