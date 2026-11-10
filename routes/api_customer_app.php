<?php

use App\Http\Controllers\Api\CustomerApp\ActivityController;
use App\Http\Controllers\Api\CustomerApp\BookingController;
use App\Http\Controllers\Api\CustomerApp\ContentController;
use App\Http\Controllers\Api\CustomerApp\ConversationController;
use App\Http\Controllers\Api\CustomerApp\FavoriteController;
use App\Http\Controllers\Api\CustomerApp\HomeController;
use App\Http\Controllers\Api\CustomerApp\NotificationController;
use App\Http\Controllers\Api\CustomerApp\ProfileController;
use App\Http\Controllers\Api\CustomerApp\ReviewController;
use App\Http\Controllers\Api\CustomerApp\SearchController;
use App\Http\Controllers\Api\CustomerApp\SupportController;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The customer app API: /api/v1/customer-app/*
|--------------------------------------------------------------------------
|
| Built to docs/CUSTOMER_API.md (the customer app specification). Its own routes, apart from the older /customer/* and /public/* ones, which stay.
|
|   - 'envelope' => 'customer-app' makes every answer and every failure use the specification's shape: {status, message, data, meta} and, on failure,
|     {status: false, code, message, errors}. The older API keeps {success, ...}.
|   - Open to everyone (a signed-in customer's token only makes it personal: is_favorite, the unread count): home, search, filters, activity pages,
|     reviews, availability, and the static pages.
|   - Customer only (`auth:sanctum`, ability `customer`): everything that is the customer's own.
|   - The language is the Accept-Language header: ar for Arabic, anything else English.
|
*/

Route::group(['prefix' => 'customer-app', 'as' => 'customer-app.', 'envelope' => 'customer-app', 'middleware' => [SetApiLocale::class, 'throttle:customer-app-read']], function () {
    Route::get('/home', [HomeController::class, 'show'])->name('home');
    Route::get('/activities', [ActivityController::class, 'index'])->name('activities.index');
    Route::get('/search/popular', [SearchController::class, 'popular'])->name('search.popular');
    Route::get('/filters', [SearchController::class, 'filters'])->name('filters');
    Route::get('/activities/{id}', [ActivityController::class, 'show'])->name('activities.show');
    Route::get('/activities/{id}/reviews', [ActivityController::class, 'reviews'])->name('activities.reviews.index');
    Route::get('/activities/{id}/availability', [ActivityController::class, 'availability'])->name('activities.availability');

    Route::get('/pages/cancellation-policy', [ContentController::class, 'cancellationPolicy'])->name('pages.cancellation');
    Route::get('/pages/terms', [ContentController::class, 'legal'])->defaults('slug', 'terms')->name('pages.terms');
    Route::get('/pages/privacy', [ContentController::class, 'legal'])->defaults('slug', 'privacy')->name('pages.privacy');
    Route::get('/settings', [ContentController::class, 'settings'])->name('settings');
    Route::get('/faqs', [ContentController::class, 'faqs'])->name('faqs');
    Route::get('/about', [ContentController::class, 'about'])->name('about');

    Route::middleware(['auth:sanctum', 'ability:customer'])->group(function () {
        Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('/favorites/{activity_id}', [FavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('/favorites/{activity_id}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

        Route::post('/activities/{id}/reviews', [ReviewController::class, 'store'])->middleware('throttle:customer-app-review')->name('activities.reviews.store');

        Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:customer-app-message')->name('conversations.store');
        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages'])->name('conversations.messages.index');
        Route::post('/conversations/{id}/messages', [ConversationController::class, 'send'])->middleware('throttle:customer-app-message')->name('conversations.messages.store');

        Route::post('/bookings/summary', [BookingController::class, 'summary'])->middleware('throttle:customer-app-summary')->name('bookings.summary');
        Route::post('/bookings', [BookingController::class, 'store'])->middleware('throttle:customer-app-booking')->name('bookings.store');
        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{id}', [BookingController::class, 'show'])->name('bookings.show');
        Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->middleware('throttle:customer-app-booking')->name('bookings.cancel');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('throttle:customer-app-account')->name('profile.destroy');
        Route::post('/auth/logout', [ProfileController::class, 'logout'])->name('auth.logout');

        Route::post('/support/tickets', [SupportController::class, 'store'])->middleware('throttle:customer-app-support')->name('support.tickets.store');
    });
});
