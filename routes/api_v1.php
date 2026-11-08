<?php

use App\Http\Controllers\Api\Account\AccountController;
use App\Http\Controllers\Api\Account\ProviderRequestController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Auth\AccountAuthController;
use App\Http\Controllers\Api\Auth\AdminAuthController;
use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\ProviderAuthController;
use App\Http\Controllers\Api\Customer\FavoriteController;
use App\Http\Controllers\Api\Customer\PaymentController;
use App\Http\Controllers\Api\Customer\ProfileController as CustomerProfileController;
use App\Http\Controllers\Api\Customer\ReviewController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\Provider\ActivityBookingOptionController;
use App\Http\Controllers\Api\Provider\ActivityController;
use App\Http\Controllers\Api\Provider\ActivityDetailsController;
use App\Http\Controllers\Api\Provider\ActivityFeatureController;
use App\Http\Controllers\Api\Provider\ActivityImageController;
use App\Http\Controllers\Api\Provider\ActivityPricingRuleController;
use App\Http\Controllers\Api\Provider\AvailabilityOverrideController;
use App\Http\Controllers\Api\Provider\DocumentController;
use App\Http\Controllers\Api\Provider\ProfileController as ProviderProfileController;
use App\Http\Controllers\Api\Provider\ReservationController;
use App\Http\Controllers\Api\Provider\ScheduleController;
use App\Http\Controllers\Api\Public\ActivityController as PublicActivityController;
use App\Http\Controllers\Api\Public\CategoryController as PublicCategoryController;
use App\Http\Controllers\Api\Shared\DeviceController;
use App\Http\Controllers\Api\Shared\NotificationController;
use App\Http\Controllers\Api\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Consolidated in Step 26 — this file previously accumulated duplicate
| route registrations (the provider/profile group and the public
| categories group were each declared three and two times respectively)
| from incremental edits across Steps 22-25 that appended instead of
| replacing. Every endpoint below is declared exactly once.
|
*/

Route::get('/ping', PingController::class)->name('api.v1.ping');

Route::prefix('auth')->name('auth.')->group(function () {
    // Staff sign-in for the admin JSON API: email and password, a bearer token back (it expires; see config/admin_api.php).
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login')
            ->name('login');
        Route::post('/logout', [AdminAuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'staff.api'])
            ->name('logout');
    });

    // Sign in to an account that already exists, whichever kind: opens the person's default account.
    Route::post('/otp/request', [AccountAuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('otp.request');
    Route::post('/otp/verify', [AccountAuthController::class, 'verifyOtp'])
        ->middleware('throttle:otp-verify')
        ->name('otp.verify');
    Route::post('/otp/resend', [AccountAuthController::class, 'resendOtp'])
        ->middleware('throttle:otp-resend')
        ->name('otp.resend');

    Route::prefix('provider')->name('provider.')->group(function () {
        Route::post('/otp/request', [ProviderAuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('otp.request');
        Route::post('/otp/verify', [ProviderAuthController::class, 'verifyOtp'])
            ->middleware('throttle:otp-verify')
            ->name('otp.verify');
        Route::post('/otp/resend', [ProviderAuthController::class, 'resendOtp'])
            ->middleware('throttle:otp-resend')
            ->name('otp.resend');
        Route::post('/logout', [ProviderAuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'ability:provider'])
            ->name('logout');
    });

    Route::prefix('customer')->name('customer.')->group(function () {
        Route::post('/otp/request', [CustomerAuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('otp.request');
        Route::post('/otp/verify', [CustomerAuthController::class, 'verifyOtp'])
            ->middleware('throttle:otp-verify')
            ->name('otp.verify');
        Route::post('/otp/resend', [CustomerAuthController::class, 'resendOtp'])
            ->middleware('throttle:otp-resend')
            ->name('otp.resend');
        Route::post('/logout', [CustomerAuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'ability:customer'])
            ->name('logout');
    });
});

Route::middleware(['auth:sanctum', 'ability:provider'])->prefix('provider')->name('provider.')->group(function () {
    // The one thing open to a provider account before approval: reading its own profile and status.
    Route::get('/profile', [ProviderProfileController::class, 'show'])->name('profile.show');

    // Everything else needs an APPROVED provider (see EnsureProviderApproved).
    Route::middleware('provider.approved')->group(function () {
        Route::put('/profile', [ProviderProfileController::class, 'update'])->name('profile.update');

        Route::apiResource('activities', ActivityController::class);
        // The provider's publish request. `submit-for-review` is its old name, kept so existing apps keep working: nobody reviews activities any more.
        Route::post('/activities/{activity}/publish', [ActivityController::class, 'publish'])->name('activities.publish');
        Route::post('/activities/{activity}/submit-for-review', [ActivityController::class, 'publish'])->name('activities.submit-for-review');

        Route::post('/activities/{activity}/images', [ActivityImageController::class, 'store'])
            ->name('activities.images.store');
        Route::delete('/activities/{activity}/images/{image}', [ActivityImageController::class, 'destroy'])
            ->name('activities.images.destroy');
        Route::put('/activities/{activity}/images/{image}/cover', [ActivityImageController::class, 'setCover'])
            ->name('activities.images.set-cover');
        Route::put('/activities/{activity}/images/reorder', [ActivityImageController::class, 'reorder'])
            ->name('activities.images.reorder');

        Route::put('/activities/{activity}/features', [ActivityFeatureController::class, 'sync'])
            ->name('activities.features.sync');
        Route::put('/activities/{activity}/options', [ActivityDetailsController::class, 'syncOptions'])
            ->name('activities.options.sync');
        Route::put('/activities/{activity}/requirements', [ActivityDetailsController::class, 'syncRequirements'])
            ->name('activities.requirements.sync');
        Route::put('/activities/{activity}/inclusions', [ActivityDetailsController::class, 'syncInclusions'])
            ->name('activities.inclusions.sync');

        Route::apiResource('activities.schedule', ScheduleController::class)
            ->parameters(['schedule' => 'period'])
            ->only(['index', 'store', 'update', 'destroy']);

        Route::apiResource('activities.overrides', AvailabilityOverrideController::class)
            ->parameters(['overrides' => 'override'])
            ->only(['index', 'store', 'update', 'destroy']);

        Route::apiResource('activities.booking-options', ActivityBookingOptionController::class)
            ->parameters(['booking-options' => 'bookingOption'])
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        Route::apiResource('activities.booking-options.pricing-rules', ActivityPricingRuleController::class)
            ->parameters(['booking-options' => 'bookingOption', 'pricing-rules' => 'pricingRule'])
            ->only(['index', 'store', 'update', 'destroy']);

        Route::get('/documents', [DocumentController::class, 'index'])
            ->name('documents.index');
        Route::post('/documents', [DocumentController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('documents.store');
        Route::delete('/documents/{document:uuid}', [DocumentController::class, 'destroy'])
            ->name('documents.destroy');

        Route::get('/reservations', [ReservationController::class, 'index'])
            ->name('reservations.index');
        Route::get('/activities/{activity}/reservations', [ReservationController::class, 'forActivity'])
            ->name('activities.reservations.index');

        Route::post('/devices', [DeviceController::class, 'store'])
            ->name('devices.store');
        Route::delete('/devices/{fcmToken}', [DeviceController::class, 'destroy'])
            ->name('devices.destroy');

        Route::get('/notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
            ->name('notifications.unread-count');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.read-all');
    });
});

Route::middleware(['auth:sanctum', 'ability:customer'])->prefix('customer')->name('customer.')->group(function () {
    Route::get('/profile', [CustomerProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [CustomerProfileController::class, 'update'])->name('profile.update');

    Route::apiResource('reservations', App\Http\Controllers\Api\Customer\ReservationController::class)
        ->only(['index', 'store', 'show']);
    Route::post('/reservations/{reservation}/cancel', [App\Http\Controllers\Api\Customer\ReservationController::class, 'cancel'])
        ->name('reservations.cancel');

    Route::post('/reservations/{reservation}/payments', [PaymentController::class, 'store'])
        ->middleware('throttle:payments')
        ->name('payments.store');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->name('payments.show');

    Route::post('/devices', [DeviceController::class, 'store'])
        ->name('devices.store');
    Route::delete('/devices/{fcmToken}', [DeviceController::class, 'destroy'])
        ->name('devices.destroy');

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');

    Route::get('/favorites', [FavoriteController::class, 'index'])
        ->name('favorites.index');
    Route::post('/favorites', [FavoriteController::class, 'store'])
        ->name('favorites.store');
    Route::delete('/favorites/{activity}', [FavoriteController::class, 'destroy'])
        ->name('favorites.destroy');

    Route::apiResource('reviews', ReviewController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});

// The signed-in person's accounts. Open to a customer token or a provider token.
Route::middleware(['auth:sanctum', 'ability:customer,provider'])->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'show'])->name('show');
    Route::put('/default', [AccountController::class, 'setDefault'])->name('default');
    Route::post('/switch', [AccountController::class, 'switch'])->name('switch');
    Route::post('/logout', [AccountController::class, 'logout'])->name('logout');

    // Becoming a provider: needs an admin's approval, and is the only provider-side thing open before it.
    Route::get('/provider-request', [ProviderRequestController::class, 'show'])->name('provider-request.show');
    Route::post('/provider-request', [ProviderRequestController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('provider-request.store');
});

/*
| Admin API. Staff sign in with POST /auth/admin/login and send the bearer token it returns.
|
| It used to be guarded by 'auth:web' (the session guard), but the api middleware group has no session, so over real
| HTTP every one of these routes answered 401 even for a signed-in admin: they only worked under a test's actingAs().
| Sessions are deliberately NOT added to the API (a cookie-authenticated JSON API needs CSRF protection); a token
| needs none.
|
| 'auth:sanctum' finds the user (token for a client; the web session still satisfies it in tests) and 'staff.api' insists
| on an active staff account whose token is an admin one, so a customer or provider token cannot reach this area. What
| each admin may DO is still decided by the policies and permissions inside the controllers.
|
| Deliberately NOT the 'admin.access' alias that routes/web.php uses: that one redirects to the login page on failure,
| which is wrong for a JSON API. Under /api/* the exception handler (bootstrap/app.php) renders failures as JSON.
*/
Route::middleware(['auth:sanctum', 'staff.api'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('/reservations', [App\Http\Controllers\Api\Admin\ReservationController::class, 'index'])
        ->name('reservations.index');
    Route::get('/reservations/{reservation}', [App\Http\Controllers\Api\Admin\ReservationController::class, 'show'])
        ->name('reservations.show');

    Route::get('/payments', [App\Http\Controllers\Api\Admin\PaymentController::class, 'index'])
        ->name('payments.index');
    Route::get('/payments/{payment}', [App\Http\Controllers\Api\Admin\PaymentController::class, 'show'])
        ->name('payments.show');
    Route::post('/payments/{payment}/refund', [App\Http\Controllers\Api\Admin\PaymentController::class, 'refund'])
        ->name('payments.refund');

    Route::post('/devices', [DeviceController::class, 'store'])
        ->name('devices.store');
    Route::delete('/devices/{fcmToken}', [DeviceController::class, 'destroy'])
        ->name('devices.destroy');

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');

    Route::get('/reviews', [App\Http\Controllers\Api\Admin\ReviewController::class, 'index'])
        ->name('reviews.index');
    Route::get('/reviews/{review}', [App\Http\Controllers\Api\Admin\ReviewController::class, 'show'])
        ->name('reviews.show');
    Route::post('/reviews/{review}/hide', [App\Http\Controllers\Api\Admin\ReviewController::class, 'hide'])
        ->name('reviews.hide');
    Route::post('/reviews/{review}/unhide', [App\Http\Controllers\Api\Admin\ReviewController::class, 'unhide'])
        ->name('reviews.unhide');

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard.index');
});

Route::prefix('public')->name('api.public.')->group(function () {
    Route::get('/categories', [PublicCategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/{category}', [PublicCategoryController::class, 'show'])->name('categories.show');
    Route::get('/activities', [PublicActivityController::class, 'index'])->name('activities.index');
    Route::get('/activities/{activity}', [PublicActivityController::class, 'show'])->name('activities.show');
    Route::get('/activities/{activity}/availability', [PublicActivityController::class, 'availability'])
        ->name('activities.availability');
    Route::get('/activities/{activity}/reviews', [PublicActivityController::class, 'reviews'])
        ->name('activities.reviews');
});

/*
| Public webhook endpoint — deliberately outside every auth middleware
| group. Trust comes entirely from PaymentGatewayInterface::handleWebhook()'s
| own signature verification inside the bound driver, never from route
| middleware — see PaymentWebhookController's own doc comment.
*/
Route::post('/webhooks/payments/{gateway}', [PaymentWebhookController::class, 'handle'])
    ->name('webhooks.payments');
