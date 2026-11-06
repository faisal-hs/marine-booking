<?php

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
|   provider.approved    and the provider must be approved by an admin (an unapproved one gets 403 with its
|                        provider_request_status). It is the only thing open before approval, in api_v1.php: GET /provider/profile.
|
| Conventions, so a new endpoint looks like the ones around it:
|
|   - one controller per area in app/Http/Controllers/Api/Provider/, extending BaseApiController (success() / error());
|   - input in a FormRequest (app/Http/Requests/Provider/...), output in an API Resource (app/Http/Resources/...);
|   - route-model binding by uuid, never by id; list endpoints paginate with per_page and current_page;
|   - write endpoints that cost something (uploads, anything that reaches a gateway) get ->middleware('throttle:...');
|   - NO closures here: they cannot be cached by "php artisan route:cache";
|   - every endpoint gets a feature test that also proves a customer token, no token and an unapproved provider are refused.
|
| tests/Feature/Provider/ProviderRouteConventionsTest.php fails if a route here (or anywhere) repeats another route's method and
| URI, repeats a route name, or is reachable without the three guards above.
|
*/

Route::middleware(['auth:sanctum', 'ability:provider'])->prefix('provider')->name('provider.')->group(function () {
    // Everything below needs an APPROVED provider.
    Route::middleware('provider.approved')->group(function () {
        // (routes go here)
    });
});
