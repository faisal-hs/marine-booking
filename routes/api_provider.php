<?php

use App\Http\Controllers\Api\ProviderApp\ActivityStatusController;
use App\Http\Controllers\Api\ProviderApp\ApplicationController;
use App\Http\Controllers\Api\ProviderApp\BookingController;
use App\Http\Controllers\Api\ProviderApp\BankAccountController;
use App\Http\Controllers\Api\ProviderApp\CalendarController;
use App\Http\Controllers\Api\ProviderApp\CategoryController;
use App\Http\Controllers\Api\ProviderApp\CustomerController;
use App\Http\Controllers\Api\ProviderApp\DashboardController;
use App\Http\Controllers\Api\ProviderApp\EarningsController;
use App\Http\Controllers\Api\ProviderApp\ProfileController;
use App\Http\Controllers\Api\ProviderApp\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider API (its own route file)
|--------------------------------------------------------------------------
|
| The routes of the provider app that are kept apart from routes/api_v1.php. bootstrap/app.php loads this file under the
| same prefix and middleware group as that one, so a route written here at '/foo' answers at
|
|     /api/v1/provider/foo        (route name: provider.foo)
|
| and already has, without repeating it:
|
|   auth:sanctum         the caller must send a valid bearer token;
|   ability:provider     and it must be a PROVIDER token (a customer or admin token gets 403);
|   provider.approved    and the provider must be approved by an admin (an unapproved one gets 403 PROVIDER_NOT_APPROVED with its
|                        provider_request_status), EXCEPT the onboarding routes at the top of the file, which exist for providers not yet
|                        approved (and GET /provider/profile in api_v1.php).
|
| Responses use the provider app spec's shape ({status, message, data, meta} / {status, code, message, errors}), chosen by the
| 'envelope' => 'provider-app' attribute on the group. The rest of the API keeps {success, ..., pagination}. See ProviderEnvelope.
|
| Conventions, so a new endpoint looks like the ones around it:
|
|   - one controller per area in app/Http/Controllers/Api/Provider/, extending BaseApiController (success() / error());
|   - input in a FormRequest (app/Http/Requests/Provider/...), output in an API Resource (app/Http/Resources/...);
|   - route-model binding by uuid, never by id; list endpoints paginate with per_page and current_page;
|   - write endpoints that cost something (uploads, anything that reaches a gateway) get a NAMED limiter of their own (->middleware('throttle:provider-...'), see AppServiceProvider): a numeric throttle:N,M shares one counter per user with every other numeric throttle;
|   - NO closures here: they cannot be cached by "php artisan route:cache";
|   - every endpoint gets a feature test that also proves a customer token, no token and an unapproved provider are refused.
|
| tests/Feature/Provider/ProviderRouteConventionsTest.php fails if a route here (or anywhere) repeats another route's method and
| URI, repeats a route name, or is reachable without the three guards above.
|
*/

// 'envelope' => 'provider-app' makes every response and every failure of these routes use the spec's shape (see ProviderEnvelope).
Route::group([
    'middleware' => ['auth:sanctum', 'ability:provider'],
    'prefix' => 'provider',
    'as' => 'provider.',
    'envelope' => 'provider-app',
], function () {
    // --- Onboarding: open to a provider an admin has NOT approved yet, because applying is how they get approved. ---
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');

    Route::get('/apply', [ApplicationController::class, 'show'])->name('apply.show');
    Route::post('/apply', [ApplicationController::class, 'store'])->middleware('throttle:provider-apply')->name('apply.store');
    Route::post('/apply/resubmit', [ApplicationController::class, 'resubmit'])->middleware('throttle:provider-apply')->name('apply.resubmit');

    // --- Everything below needs an APPROVED provider. ---
    Route::middleware('provider.approved')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.patch');
        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->middleware('throttle:provider-booking-cancel')->name('bookings.cancel');
        Route::get('/calendar', [CalendarController::class, 'show'])->name('calendar.show');
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');

        Route::patch('/activities/{id}/status', [ActivityStatusController::class, 'update'])->name('activities.status');

        // The wallet: earnings come from the ledger; withdrawals are reserved at once and paid by an admin by hand.
        Route::get('/earnings', [EarningsController::class, 'summary'])->name('earnings.summary');
        Route::get('/earnings/statement', [EarningsController::class, 'statement'])->name('earnings.statement');
        Route::get('/earnings/report', [EarningsController::class, 'report'])->name('earnings.report');
        Route::get('/bank-account', [BankAccountController::class, 'show'])->name('bank-account.show');
        Route::put('/bank-account', [BankAccountController::class, 'update'])->middleware('throttle:provider-bank-account')->name('bank-account.update');
        Route::post('/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:provider-withdrawals')->name('withdrawals.store');
        Route::get('/withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('/withdrawals/{id}', [WithdrawalController::class, 'show'])->name('withdrawals.show');

        // (routes go here)
    });
});

// The statement itself, reached through the expiring link that GET /provider/earnings/statement hands out. It carries no token on purpose (a browser or
// a PDF viewer opens it): the SIGNATURE proves the link was issued for that provider and that month, and it stops working after a few minutes.
Route::group(['prefix' => 'provider', 'as' => 'provider.', 'envelope' => 'provider-app'], function () {
    Route::get('/earnings/statement/download', [EarningsController::class, 'download'])->middleware('signed')->name('earnings.statement.download');
});
