<?php

namespace App\Providers;

use App\Events\ActivityApproved;
use App\Events\ActivityPublished;
use App\Events\ActivityRejected;
use App\Events\PaymentFailed;
use App\Events\PaymentRefunded;
use App\Events\ProviderRejected;
use App\Events\ProviderVerified;
use App\Events\ReservationCancelled;
use App\Events\ReservationConfirmed;
use App\Events\ReservationCreated;
use App\Events\ReservationExpired;
use App\Listeners\Notifications\SendActivityApprovedNotifications;
use App\Listeners\Notifications\SendActivityPublishedNotifications;
use App\Listeners\Notifications\SendActivityRejectedNotifications;
use App\Listeners\Notifications\SendPaymentFailedNotifications;
use App\Listeners\Notifications\SendPaymentRefundedNotifications;
use App\Listeners\Notifications\SendProviderRejectedNotifications;
use App\Listeners\Notifications\SendProviderVerifiedNotifications;
use App\Listeners\Notifications\SendReservationCancelledNotifications;
use App\Listeners\Notifications\SendReservationConfirmedNotifications;
use App\Listeners\Notifications\SendReservationCreatedNotifications;
use App\Listeners\Notifications\SendReservationExpiredNotifications;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
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
        $this->registerNotificationChannels();
        $this->registerNotificationListeners();
    }

    /**
     * Laravel's Notification system requires every custom channel string
     * used in a Notification's via() array to be explicitly registered
     * with the ChannelManager — simply having a class named FcmChannel/
     * SmsChannel is NOT enough on its own. Missing this step throws
     * "Driver [fcm] not supported." the moment any real notification
     * tries to use it, even though the channel classes themselves are
     * correct (confirmed by their own passing unit tests, which
     * instantiate the channels directly and never exercise this
     * registration step at all).
     */
    protected function registerNotificationChannels(): void
    {
        Notification::extend('sms', fn($app) => $app->make(SmsChannel::class));
        Notification::extend('fcm', fn($app) => $app->make(FcmChannel::class));
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

    /**
     * Explicit registration rather than relying on Laravel's
     * auto-discovery convention (Listener classes in app/Listeners with
     * a type-hinted handle() method) — this project has no
     * EventServiceProvider (removed by default in Laravel 11+), so
     * making every event->listener mapping visible here in one place is
     * clearer than depending on discovery working silently.
     */
    protected function registerNotificationListeners(): void
    {
        Event::listen(ReservationCreated::class, SendReservationCreatedNotifications::class);
        Event::listen(ReservationConfirmed::class, SendReservationConfirmedNotifications::class);
        Event::listen(ReservationCancelled::class, SendReservationCancelledNotifications::class);
        Event::listen(ReservationExpired::class, SendReservationExpiredNotifications::class);

        Event::listen(PaymentFailed::class, SendPaymentFailedNotifications::class);
        Event::listen(PaymentRefunded::class, SendPaymentRefundedNotifications::class);

        Event::listen(ActivityApproved::class, SendActivityApprovedNotifications::class);
        Event::listen(ActivityRejected::class, SendActivityRejectedNotifications::class);
        Event::listen(ActivityPublished::class, SendActivityPublishedNotifications::class);

        Event::listen(ProviderVerified::class, SendProviderVerifiedNotifications::class);
        Event::listen(ProviderRejected::class, SendProviderRejectedNotifications::class);
    }
}
