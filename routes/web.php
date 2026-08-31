<?php

use App\Http\Controllers\Web\Admin\AuthController;
use App\Http\Controllers\Web\Admin\DashboardController;
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
        Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    });

    Route::middleware(['auth:web', 'admin.access'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::resource('users', UserController::class)->except(['destroy'])->parameters(['users' => 'user']);
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
