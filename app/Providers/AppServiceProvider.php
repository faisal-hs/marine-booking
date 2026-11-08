<?php

namespace App\Providers;

use App\Events\PaymentFailed;
use App\Events\ActivitySuspended;
use App\Events\ActivitySuspensionLifted;
use App\Events\PaymentRefunded;
use App\Events\ProviderRejected;
use App\Events\ProviderRequestSubmitted;
use App\Events\ProviderVerified;
use App\Events\ReservationCancelled;
use App\Events\ReservationConfirmed;
use App\Events\ReservationCreated;
use App\Events\ReservationExpired;
use App\Listeners\Notifications\SendPaymentFailedNotifications;
use App\Listeners\Notifications\SendActivitySuspendedNotifications;
use App\Listeners\Notifications\SendActivitySuspensionLiftedNotifications;
use App\Listeners\Notifications\SendPaymentRefundedNotifications;
use App\Listeners\Notifications\SendProviderRejectedNotifications;
use App\Listeners\Notifications\SendProviderRequestSubmittedNotifications;
use App\Listeners\Notifications\SendProviderVerifiedNotifications;
use App\Listeners\Notifications\SendReservationCancelledNotifications;
use App\Listeners\Notifications\SendReservationConfirmedNotifications;
use App\Listeners\Notifications\SendReservationCreatedNotifications;
use App\Listeners\Notifications\SendReservationExpiredNotifications;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\SmsChannel;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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
        $this->registerViewComposers();
    }

    /**
     * public.layout's header nav renders the active category list on
     * every single public page — a View Composer means every Controller
     * doesn't need to remember to pass 'categories' itself (and a
     * Controller that explicitly does pass it, like HomeController and
     * CategoryController for their own page body, simply overrides this
     * with no conflict — explicit view data always wins over composer
     * data for the same key).
     */
    protected function registerViewComposers(): void
    {
        View::composer('public.*', function ($view) {
            $view->with('categories', $view->getData()['categories'] ?? app(CategoryRepositoryInterface::class)->activeOrdered());
        });
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
        Notification::extend('sms', fn ($app) => $app->make(SmsChannel::class));
        Notification::extend('fcm', fn ($app) => $app->make(FcmChannel::class));
    }

    protected function configureRateLimiting(): void
    {
        // Admin login: keyed by email+IP so one attacker guessing one
        // admin's password cannot lock every admin account out (a global
        // or IP-only limiter would let an attacker exhaust the limit for
        // everyone sharing that IP, e.g. an office network).
        RateLimiter::for('admin-login', function (Request $request) {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // OTP verify: keyed by phone+IP. A phone-only key would let an
        // attacker on many IPs still be limited (good), but a global
        // limiter would mean one attacker verifying against phone A
        // could exhaust the limit for phone B's legitimate owner — this
        // combination keeps the limit scoped to one specific attack
        // target at a time.
        RateLimiter::for('otp-verify', function (Request $request) {
            $key = (string) $request->input('phone').'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // OTP resend: tighter limit, same scoping rationale as verify.
        // Asking for a code SENDS A MESSAGE to whatever phone number is given, so this is the endpoint that can be turned
        // on a third party (SMS bombing) or run up the SMS bill. Two limits: how often one phone can be asked for a code from
        // one place, and how many codes one place can ask for in all, which stops a script walking through many numbers.
        // Starting a payment is what costs money once the mock gateway is replaced by a real one: every attempt reaches the
        // gateway. Ten a minute per customer is far above what a person does and well below what a script does.
        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(10)->by('payments:'.($request->user()?->id ?? $request->ip()));
        });

        // The provider app's write endpoints each have a budget of their own. (A numeric throttle:10,1 would NOT: for a signed-in user all numeric throttles
        // share one counter, so doing ordinary things could lock a provider out of withdrawing.)
        RateLimiter::for('provider-apply', fn (Request $request) => Limit::perMinute(20)->by('provider-apply:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('provider-booking-cancel', fn (Request $request) => Limit::perMinute(30)->by('provider-booking-cancel:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('provider-bank-account', fn (Request $request) => Limit::perMinute(20)->by('provider-bank-account:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('provider-verification', fn (Request $request) => Limit::perMinute(20)->by('provider-verification:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('provider-withdrawals', fn (Request $request) => Limit::perMinute(10)->by('provider-withdrawals:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('otp-request', function (Request $request) {
            return [
                Limit::perMinute(5)->by('otp-request:'.(string) $request->input('phone').'|'.$request->ip()),
                Limit::perMinute(30)->by('otp-request-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('otp-resend', function (Request $request) {
            $key = (string) $request->input('phone').'|'.$request->ip();

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
        Event::listen(PaymentRefunded::class, \App\Listeners\Wallet\SyncWalletOnRefund::class);

        Event::listen(ActivitySuspended::class, SendActivitySuspendedNotifications::class);
        Event::listen(ActivitySuspensionLifted::class, SendActivitySuspensionLiftedNotifications::class);

        Event::listen(ProviderVerified::class, SendProviderVerifiedNotifications::class);
        Event::listen(ProviderRequestSubmitted::class, SendProviderRequestSubmittedNotifications::class);
        Event::listen(ProviderRejected::class, SendProviderRejectedNotifications::class);
    }
}
