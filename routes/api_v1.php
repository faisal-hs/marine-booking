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

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'pong',
        'data' => ['timestamp' => now()->toIso8601String()],
    ]);
})->name('api.v1.ping');

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
});

Route::middleware(['auth:sanctum', 'ability:customer'])->prefix('customer')->name('customer.')->group(function () {
    Route::get('/profile', [CustomerProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [CustomerProfileController::class, 'update'])->name('profile.update');
});

Route::prefix('public')->name('public.')->group(function () {
    Route::get('/categories', [PublicCategoryController::class, 'index'])->name('categories.index');
    Route::get('/activities', [PublicActivityController::class, 'index'])->name('activities.index');
    Route::get('/activities/{slug}', [PublicActivityController::class, 'show'])->name('activities.show');
});
