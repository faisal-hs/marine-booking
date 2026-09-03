<?php

use App\Http\Controllers\Web\Admin\ActivityController;
use App\Http\Controllers\Web\Admin\AuthController;
use App\Http\Controllers\Web\Admin\CategoryController;
use App\Http\Controllers\Web\Admin\DashboardController;
use App\Http\Controllers\Web\Admin\FeatureDefinitionController;
use App\Http\Controllers\Web\Admin\PaymentController;
use App\Http\Controllers\Web\Admin\ProviderController;
use App\Http\Controllers\Web\Admin\ReservationController;
use App\Http\Controllers\Web\Admin\ReviewController;
use App\Http\Controllers\Web\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->middleware('locale')->group(function () {
    Route::get('/locale/{locale}', function (string $locale) {
        if (in_array($locale, ['en', 'ar'], true)) {
            session(['admin_locale' => $locale]);
        }

        return back();
    })->name('locale.switch');

    Route::middleware('guest:web')->group(function () {
        Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:admin-login')
            ->name('login.submit');
    });

    // Every authenticated admin surface lives inside this single group.
    // A route placed outside this group has NO authentication and NO
    // admin-status check applied to it — see Step 26 audit finding
    // CRITICAL-1: feature-definitions was previously registered outside
    // this group by mistake. Do not add new admin routes outside it.
    Route::middleware(['auth:web', 'admin.access'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::resource('users', UserController::class)->except(['destroy'])->parameters(['users' => 'user']);
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::resource('categories', CategoryController::class)->except(['show', 'destroy']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::resource('feature-definitions', FeatureDefinitionController::class)
            ->except(['show', 'destroy'])
            ->parameters(['feature-definitions' => 'featureDefinition']);
        Route::delete('/feature-definitions/{featureDefinition}', [FeatureDefinitionController::class, 'destroy'])
            ->name('feature-definitions.destroy');

        Route::get('/activities', [ActivityController::class, 'index'])->name('activities.index');
        Route::post('/activities/{activity}/approve', [ActivityController::class, 'approve'])->name('activities.approve');
        Route::post('/activities/{activity}/reject', [ActivityController::class, 'reject'])->name('activities.reject');
        Route::post('/activities/{activity}/publish', [ActivityController::class, 'publish'])->name('activities.publish');
        Route::post('/activities/{activity}/deactivate', [ActivityController::class, 'deactivate'])->name('activities.deactivate');
        Route::get('/activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');

        Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');

        Route::get('/providers', [ProviderController::class, 'index'])->name('providers.index');
        Route::get('/providers/{provider}', [ProviderController::class, 'show'])->name('providers.show');
        Route::post('/providers/{provider}/verify', [ProviderController::class, 'verify'])->name('providers.verify');
        Route::post('/providers/{provider}/reject', [ProviderController::class, 'reject'])->name('providers.reject');

        Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::get('/reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
        Route::post('/reviews/{review}/hide', [ReviewController::class, 'hide'])->name('reviews.hide');
        Route::post('/reviews/{review}/unhide', [ReviewController::class, 'unhide'])->name('reviews.unhide');
    });
});
