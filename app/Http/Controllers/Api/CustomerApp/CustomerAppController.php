<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ProviderApi\ProviderEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** The customer app's controllers answer as the specification shows: {status, message, data} and, for a page, {meta}. */
abstract class CustomerAppController extends Controller
{
    /** The signed-in customer of a route that requires one. */
    protected function customer(Request $request): Customer
    {
        return $request->user()->customer;
    }

    /** The customer looking at a page that is open to guests too (a guest is null). A token that is not a customer's counts as a guest. */
    protected function viewer(Request $request): ?Customer
    {
        $user = $request->user('sanctum');

        return $user && $user->tokenCan('customer') ? $user->customer : null;
    }

    protected function ok(mixed $data = null, string $message = 'Success'): JsonResponse
    {
        return ProviderEnvelope::ok($data, $message);
    }

    protected function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return ProviderEnvelope::ok($data, $message, 201);
    }

    protected function page(LengthAwarePaginator $page, mixed $data, string $message = 'Success'): JsonResponse
    {
        return ProviderEnvelope::ok($data, $message, 200, ProviderEnvelope::meta($page));
    }
}
