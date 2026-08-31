<?php

use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\ProviderAuthController;
use Illuminate\Support\Facades\Route;

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
        Route::post('/otp/verify', [ProviderAuthController::class, 'verifyOtp'])->name('otp.verify');
        Route::post('/otp/resend', [ProviderAuthController::class, 'resendOtp'])->name('otp.resend');
        Route::post('/logout', [ProviderAuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'ability:provider'])
            ->name('logout');
    });

    Route::prefix('customer')->name('customer.')->group(function () {
        Route::post('/otp/request', [CustomerAuthController::class, 'requestOtp'])->name('otp.request');
        Route::post('/otp/verify', [CustomerAuthController::class, 'verifyOtp'])->name('otp.verify');
        Route::post('/otp/resend', [CustomerAuthController::class, 'resendOtp'])->name('otp.resend');
        Route::post('/logout', [CustomerAuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'ability:customer'])
            ->name('logout');
    });
});
