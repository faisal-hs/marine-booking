<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Policy registration lives in RepositoryServiceProvider::boot() —
     * it previously also existed here as a duplicate (harmless, since
     * Gate::policy() is idempotent, but real drift). Consolidated to one
     * location as part of Step 26 hardening.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        // Admin login: keyed by email+IP so one attacker guessing one
        // admin's password cannot lock every admin account out (a global
        // or IP-only limiter would let an attacker exhaust the limit for
        // everyone sharing that IP, e.g. an office network).
        RateLimiter::for('admin-login', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // OTP verify: keyed by phone+IP. A phone-only key would let an
        // attacker on many IPs still be limited (good), but a global
        // limiter would mean one attacker verifying against phone A
        // could exhaust the limit for phone B's legitimate owner — this
        // combination keeps the limit scoped to one specific attack
        // target at a time.
        RateLimiter::for('otp-verify', function (Request $request) {
            $key = (string) $request->input('phone') . '|' . $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // OTP resend: tighter limit, same scoping rationale as verify.
        RateLimiter::for('otp-resend', function (Request $request) {
            $key = (string) $request->input('phone') . '|' . $request->ip();

            return Limit::perMinute(3)->by($key);
        });
    }
}
