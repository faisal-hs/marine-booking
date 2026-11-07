<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Support\ProviderApi\ProviderEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base of the provider app controllers: answers in the spec's envelope, and knows who the provider is.
 *
 * The provider is ALWAYS the authenticated user's own: nothing the client sends (a provider_id, a customer_id) is trusted, and every
 * query a controller makes is scoped through provider().
 */
abstract class ProviderAppController extends Controller
{
    protected function provider(Request $request): Provider
    {
        return $request->user()->provider;
    }

    protected function ok(mixed $data = null, string $message = 'Success'): JsonResponse
    {
        return ProviderEnvelope::ok($data, $message);
    }

    protected function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return ProviderEnvelope::ok($data, $message, 201);
    }

    /** A page of results: $data is whatever the screen needs around the items (for example the counts beside a list). */
    protected function page(LengthAwarePaginator $page, mixed $data, string $message = 'Success'): JsonResponse
    {
        return ProviderEnvelope::ok($data, $message, 200, ProviderEnvelope::meta($page));
    }
}
