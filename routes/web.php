<?php

use App\Http\Controllers\Web\Admin\ActivityController;
use App\Http\Controllers\Web\Admin\AuthController;
use App\Http\Controllers\Web\Admin\CategoryController;
use App\Http\Controllers\Web\Admin\CustomerController;
use App\Http\Controllers\Web\Admin\ProviderRequestController;
use App\Http\Controllers\Web\Admin\BroadcastController;
use App\Http\Controllers\Web\Admin\NotificationLogController;
use App\Http\Controllers\Web\Admin\NotificationController;
use App\Http\Controllers\Web\Admin\DashboardController;
use App\Http\Controllers\Web\Admin\FeatureDefinitionController;
use App\Http\Controllers\Web\Admin\PaymentController;
use App\Http\Controllers\Web\Admin\ProviderController;
use App\Http\Controllers\Web\Admin\ReportController;
use App\Http\Controllers\Web\Admin\ReservationController;
use App\Http\Controllers\Web\Admin\ReviewController;
use App\Http\Controllers\Web\Admin\UserController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\Public\ActivityController as PublicActivityController;
use App\Http\Controllers\Web\Public\CategoryController as PublicCategoryController;
use App\Http\Controllers\Web\Public\HomeController as PublicHomeController;
use Illuminate\Support\Facades\Route;

/*
| Public website — browse-only per the confirmed design (booking happens
| via the separate customer mobile app, which consumes the JSON API
| directly). Uses its OWN 'public_locale' session key (via
| middleware('locale:public')) so a customer's locale choice here never
| affects, or is affected by, an admin's own 'admin_locale' session
| preference — see SetLocale's own doc comment.
*/

Route::middleware('locale:public')->name('public.')->group(function () {
    Route::get('/locale/{locale}', [LocaleController::class, 'switchPublic'])->name('locale.switch');

    Route::get('/', [PublicHomeController::class, 'index'])->name('home');
    Route::get('/about', [PublicHomeController::class, 'about'])->name('about');
    Route::get('/categories/{category:slug}', [PublicCategoryController::class, 'show'])->name('categories.show');
    Route::get('/activities', [PublicActivityController::class, 'index'])->name('activities.index');
    Route::get('/activities/{activity}', [PublicActivityController::class, 'show'])->name('activities.show');
});

Route::prefix('admin')->name('admin.')->middleware('locale')->group(function () {
    Route::get('/locale/{locale}', [LocaleController::class, 'switchAdmin'])->name('locale.switch');

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
        // "create" must come before {activity}, or it would be read as an activity id.
        Route::get('/activities/create', [ActivityController::class, 'create'])->name('activities.create');
        Route::post('/activities', [ActivityController::class, 'store'])->name('activities.store');
        // withTrashed: a deleted activity can still be opened (and restored) from the "deleted" list.
        Route::get('/activities/{activity}', [ActivityController::class, 'show'])->withTrashed()->name('activities.show');
        Route::get('/activities/{activity}/edit', [ActivityController::class, 'edit'])->name('activities.edit');
        Route::put('/activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
        Route::delete('/activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
        Route::post('/activities/{activity}/restore', [ActivityController::class, 'restore'])->withTrashed()->name('activities.restore');
        Route::put('/activities/{activity}/features', [ActivityController::class, 'syncFeatures'])->name('activities.features');
        Route::put('/activities/{activity}/requirements', [ActivityController::class, 'syncRequirements'])->name('activities.requirements');
        Route::put('/activities/{activity}/inclusions', [ActivityController::class, 'syncInclusions'])->name('activities.inclusions');
        Route::post('/activities/{activity}/images', [ActivityController::class, 'storeImages'])->name('activities.images.store');
        Route::delete('/activities/{activity}/images/{image}', [ActivityController::class, 'destroyImage'])->name('activities.images.destroy');
        Route::put('/activities/{activity}/images/{image}/cover', [ActivityController::class, 'setCover'])->name('activities.images.cover');

        Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');

        Route::get('/providers', [ProviderController::class, 'index'])->name('providers.index');
        // "create" must be registered before {provider}, or it would be read as a provider id.
        Route::get('/providers/create', [ProviderController::class, 'create'])->name('providers.create');
        Route::post('/providers', [ProviderController::class, 'store'])->name('providers.store');
        Route::get('/providers/{provider}', [ProviderController::class, 'show'])->name('providers.show');
        Route::get('/providers/{provider}/edit', [ProviderController::class, 'edit'])->name('providers.edit');
        Route::put('/providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
        Route::delete('/providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
        Route::post('/providers/{provider}/suspend', [ProviderController::class, 'suspend'])->name('providers.suspend');
        Route::post('/providers/{provider}/reactivate', [ProviderController::class, 'reactivate'])->name('providers.reactivate');
        Route::post('/providers/{provider}/restore', [ProviderController::class, 'restore'])->name('providers.restore');

        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        // "create" must be registered before {customer}, or it would be read as a customer id.
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        Route::post('/customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
        Route::post('/customers/{customer}/reactivate', [CustomerController::class, 'reactivate'])->name('customers.reactivate');
        Route::post('/customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore');

        // Requests to become a provider. Only admins who hold provider_requests.view / .review.
        Route::get('/provider-requests', [ProviderRequestController::class, 'index'])->name('provider-requests.index');
        Route::get('/provider-requests/{providerRequest}', [ProviderRequestController::class, 'show'])->name('provider-requests.show');
        Route::post('/provider-requests/{providerRequest}/approve', [ProviderRequestController::class, 'approve'])->name('provider-requests.approve');
        Route::post('/provider-requests/{providerRequest}/reject', [ProviderRequestController::class, 'reject'])->name('provider-requests.reject');
        Route::get('/provider-requests/{providerRequest}/documents/{document}', [ProviderRequestController::class, 'downloadDocument'])->name('provider-requests.documents.download');

        // Notifications: the admin's own inbox, the log of everything sent to anyone, and
        // broadcast announcements. The fixed paths (log, broadcasts, read-all) are registered
        // before {notification} so they are never read as a notification id.
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

        Route::get('/notifications/log', [NotificationLogController::class, 'index'])->name('notifications.log');
        Route::get('/notifications/log/{notification}', [NotificationLogController::class, 'show'])->name('notifications.log.show');
        Route::delete('/notifications/log/{notification}', [NotificationLogController::class, 'destroy'])->name('notifications.log.destroy');

        Route::get('/notifications/broadcasts', [BroadcastController::class, 'index'])->name('notifications.broadcasts.index');
        Route::get('/notifications/broadcasts/create', [BroadcastController::class, 'create'])->name('notifications.broadcasts.create');
        Route::post('/notifications/broadcasts', [BroadcastController::class, 'store'])->name('notifications.broadcasts.store');
        Route::get('/notifications/broadcasts/{broadcast}', [BroadcastController::class, 'show'])->name('notifications.broadcasts.show');
        Route::get('/notifications/broadcasts/{broadcast}/edit', [BroadcastController::class, 'edit'])->name('notifications.broadcasts.edit');
        Route::put('/notifications/broadcasts/{broadcast}', [BroadcastController::class, 'update'])->name('notifications.broadcasts.update');
        Route::post('/notifications/broadcasts/{broadcast}/send', [BroadcastController::class, 'send'])->name('notifications.broadcasts.send');
        Route::delete('/notifications/broadcasts/{broadcast}', [BroadcastController::class, 'destroy'])->name('notifications.broadcasts.destroy');

        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('/notifications/{notification}/unread', [NotificationController::class, 'markUnread'])->name('notifications.unread');
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::get('/providers/{provider}/documents/{document:uuid}', [ProviderController::class, 'downloadDocument'])->name('providers.documents.download');
        Route::post('/providers/{provider}/verify', [ProviderController::class, 'verify'])->name('providers.verify');
        Route::post('/providers/{provider}/reject', [ProviderController::class, 'reject'])->name('providers.reject');

        Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::get('/reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
        Route::post('/reviews/{review}/hide', [ReviewController::class, 'hide'])->name('reviews.hide');
        Route::post('/reviews/{review}/unhide', [ReviewController::class, 'unhide'])->name('reviews.unhide');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export/{type}', [ReportController::class, 'export'])->name('reports.export');
    });
});
