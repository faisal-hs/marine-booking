<?php

use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\ProviderAuthController;
use App\Http\Controllers\Api\Customer\ProfileController as CustomerProfileController;
use App\Http\Controllers\Api\Provider\ActivityController;
use App\Http\Controllers\Api\Provider\ActivityDetailsController;
use App\Http\Controllers\Api\Provider\ActivityFeatureController;
use App\Http\Controllers\Api\Provider\ActivityImageController;
use App\Http\Controllers\Api\Provider\ProfileController as ProviderProfileController;
use App\Http\Controllers\Api\Public\ActivityController as PublicActivityController;
use App\Http\Controllers\Api\Public\CategoryController as PublicCategoryController;
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

Route::get('/ping', \App\Http\Controllers\Api\PingController::class)->name('api.v1.ping');

Route::prefix('auth')->name('auth.')->group(function () {

    Route::prefix('provider')->name('provider.')->group(function () {
        Route::post('/otp/request', [ProviderAuthController::class, 'requestOtp'])->name('otp.request');
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
        Route::post('/otp/request', [CustomerAuthController::class, 'requestOtp'])->name('otp.request');
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
    Route::get('/profile', [ProviderProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProviderProfileController::class, 'update'])->name('profile.update');

    Route::apiResource('activities', ActivityController::class);
    Route::post('/activities/{activity}/submit-for-review', [ActivityController::class, 'submitForReview'])
        ->name('activities.submit-for-review');

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

    Route::apiResource('activities.schedule', \App\Http\Controllers\Api\Provider\ScheduleController::class)
        ->parameters(['schedule' => 'period'])
        ->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('activities.overrides', \App\Http\Controllers\Api\Provider\AvailabilityOverrideController::class)
        ->parameters(['overrides' => 'override'])
        ->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('activities.booking-options', \App\Http\Controllers\Api\Provider\ActivityBookingOptionController::class)
        ->parameters(['booking-options' => 'bookingOption'])
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::apiResource('activities.booking-options.pricing-rules', \App\Http\Controllers\Api\Provider\ActivityPricingRuleController::class)
        ->parameters(['booking-options' => 'bookingOption', 'pricing-rules' => 'pricingRule'])
        ->only(['index', 'store', 'update', 'destroy']);

    Route::get('/documents', [\App\Http\Controllers\Api\Provider\DocumentController::class, 'index'])
        ->name('documents.index');
    Route::post('/documents', [\App\Http\Controllers\Api\Provider\DocumentController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('documents.store');
    Route::delete('/documents/{document:uuid}', [\App\Http\Controllers\Api\Provider\DocumentController::class, 'destroy'])
        ->name('documents.destroy');

    Route::get('/reservations', [\App\Http\Controllers\Api\Provider\ReservationController::class, 'index'])
        ->name('reservations.index');
    Route::get('/activities/{activity}/reservations', [\App\Http\Controllers\Api\Provider\ReservationController::class, 'forActivity'])
        ->name('activities.reservations.index');

    Route::post('/devices', [\App\Http\Controllers\Api\Shared\DeviceController::class, 'store'])
        ->name('devices.store');
    Route::delete('/devices/{fcmToken}', [\App\Http\Controllers\Api\Shared\DeviceController::class, 'destroy'])
        ->name('devices.destroy');

    Route::get('/notifications', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');
});

Route::middleware(['auth:sanctum', 'ability:customer'])->prefix('customer')->name('customer.')->group(function () {
    Route::get('/profile', [CustomerProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [CustomerProfileController::class, 'update'])->name('profile.update');

    Route::apiResource('reservations', \App\Http\Controllers\Api\Customer\ReservationController::class)
        ->only(['index', 'store', 'show']);
    Route::post('/reservations/{reservation}/cancel', [\App\Http\Controllers\Api\Customer\ReservationController::class, 'cancel'])
        ->name('reservations.cancel');

    Route::post('/reservations/{reservation}/payments', [\App\Http\Controllers\Api\Customer\PaymentController::class, 'store'])
        ->name('payments.store');
    Route::get('/payments/{payment}', [\App\Http\Controllers\Api\Customer\PaymentController::class, 'show'])
        ->name('payments.show');

    Route::post('/devices', [\App\Http\Controllers\Api\Shared\DeviceController::class, 'store'])
        ->name('devices.store');
    Route::delete('/devices/{fcmToken}', [\App\Http\Controllers\Api\Shared\DeviceController::class, 'destroy'])
        ->name('devices.destroy');

    Route::get('/notifications', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');

    Route::get('/favorites', [\App\Http\Controllers\Api\Customer\FavoriteController::class, 'index'])
        ->name('favorites.index');
    Route::post('/favorites', [\App\Http\Controllers\Api\Customer\FavoriteController::class, 'store'])
        ->name('favorites.store');
    Route::delete('/favorites/{activity}', [\App\Http\Controllers\Api\Customer\FavoriteController::class, 'destroy'])
        ->name('favorites.destroy');

    Route::apiResource('reviews', \App\Http\Controllers\Api\Customer\ReviewController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});

/*
| Admin API — read-only. Uses the 'auth:web' session guard (the SAME
| guard the Blade admin panel already authenticates with; admins never
| receive Sanctum tokens in this project), deliberately NOT the
| 'admin.access' middleware alias used by routes/web.php's Blade
| routes — that alias redirects to the login page on failure, which is
| wrong for a JSON API. Being under /api/* is enough for this project's
| existing exception handler (bootstrap/app.php) to render
| Authentication/AuthorizationException as JSON automatically.
| Permission enforcement happens via ReservationPolicy inside the
| Controller, matching ActivityPolicy's existing admin-method pattern.
*/
Route::middleware(['auth:web'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('/reservations', [\App\Http\Controllers\Api\Admin\ReservationController::class, 'index'])
        ->name('reservations.index');
    Route::get('/reservations/{reservation}', [\App\Http\Controllers\Api\Admin\ReservationController::class, 'show'])
        ->name('reservations.show');

    Route::get('/payments', [\App\Http\Controllers\Api\Admin\PaymentController::class, 'index'])
        ->name('payments.index');
    Route::get('/payments/{payment}', [\App\Http\Controllers\Api\Admin\PaymentController::class, 'show'])
        ->name('payments.show');
    Route::post('/payments/{payment}/refund', [\App\Http\Controllers\Api\Admin\PaymentController::class, 'refund'])
        ->name('payments.refund');

    Route::post('/devices', [\App\Http\Controllers\Api\Shared\DeviceController::class, 'store'])
        ->name('devices.store');
    Route::delete('/devices/{fcmToken}', [\App\Http\Controllers\Api\Shared\DeviceController::class, 'destroy'])
        ->name('devices.destroy');

    Route::get('/notifications', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');

    Route::get('/reviews', [\App\Http\Controllers\Api\Admin\ReviewController::class, 'index'])
        ->name('reviews.index');
    Route::get('/reviews/{review}', [\App\Http\Controllers\Api\Admin\ReviewController::class, 'show'])
        ->name('reviews.show');
    Route::post('/reviews/{review}/hide', [\App\Http\Controllers\Api\Admin\ReviewController::class, 'hide'])
        ->name('reviews.hide');
    Route::post('/reviews/{review}/unhide', [\App\Http\Controllers\Api\Admin\ReviewController::class, 'unhide'])
        ->name('reviews.unhide');

    Route::get('/dashboard', [\App\Http\Controllers\Api\Admin\DashboardController::class, 'index'])
        ->name('dashboard.index');
});

Route::prefix('public')->name('api.public.')->group(function () {
    Route::get('/categories', [PublicCategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/{category}', [PublicCategoryController::class, 'show'])->name('categories.show');
    Route::get('/activities', [PublicActivityController::class, 'index'])->name('activities.index');
    Route::get('/activities/{activity}', [PublicActivityController::class, 'show'])->name('activities.show');
    Route::get('/activities/{activity}/availability', [\App\Http\Controllers\Api\Public\ActivityController::class, 'availability'])
        ->name('activities.availability');
    Route::get('/activities/{activity}/reviews', [\App\Http\Controllers\Api\Public\ActivityController::class, 'reviews'])
        ->name('activities.reviews');
});

/*
| Public webhook endpoint — deliberately outside every auth middleware
| group. Trust comes entirely from PaymentGatewayInterface::handleWebhook()'s
| own signature verification inside the bound driver, never from route
| middleware — see PaymentWebhookController's own doc comment.
*/
Route::post('/webhooks/payments/{gateway}', [\App\Http\Controllers\Api\Webhooks\PaymentWebhookController::class, 'handle'])
    ->name('webhooks.payments');
