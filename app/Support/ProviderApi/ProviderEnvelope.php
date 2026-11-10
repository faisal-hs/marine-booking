<?php

namespace App\Support\ProviderApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Route;

/**
 * The response shape of the provider app API, as the specification defines it:
 *
 *     success  { "status": true,  "message": "...", "data": ..., "meta": { current_page, last_page, per_page, total } }
 *     failure  { "status": false, "code": "VALIDATION_ERROR", "message": "...", "errors": { ... } }
 *
 * The rest of the API keeps its own envelope ({ "success": ..., "pagination": ... }). Routes written for the provider app say so
 * with the group attribute 'envelope' => 'provider-app' (routes/api_provider.php), and the exception handler in bootstrap/app.php
 * looks at that attribute, so even a failure that happens before any controller runs (no token, wrong token) comes back in this shape.
 *
 * Changing the envelope later is a change to this one class.
 */
final class ProviderEnvelope
{
    public const ROUTE_ATTRIBUTE = 'envelope';

    public const NAME = 'provider-app';

    /** The customer app's routes answer in the same shape. */
    public const CUSTOMER_NAME = 'customer-app';

    public static function applies(?Request $request): bool
    {
        $route = $request?->route();

        return $route instanceof Route && in_array($route->getAction(self::ROUTE_ATTRIBUTE), [self::NAME, self::CUSTOMER_NAME], true);
    }

    /** @param  array<string, int>|null  $meta */
    public static function ok(mixed $data = null, string $message = 'Success', int $status = 200, ?array $meta = null): JsonResponse
    {
        $payload = ['status' => true, 'message' => $message, 'data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>|null  $errors  field-level errors
     * @param  array<string, mixed>|null  $data  anything the client needs to act on the failure (for example where an application stands)
     */
    public static function error(string $message, int $status, ?string $code = null, ?array $errors = null, ?array $data = null): JsonResponse
    {
        $payload = [
            'status' => false,
            'code' => $code ?? self::codeFor($status),
            'message' => $message,
            // Always an object, as the spec shows, even when there is nothing to say.
            'errors' => (object) ($errors ?? []),
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    /** The spec's error code for a status, when the failure did not choose a more specific one. */
    public static function codeFor(int $status): string
    {
        return match (true) {
            $status === 401 => ErrorCode::UNAUTHENTICATED,
            $status === 403 => ErrorCode::FORBIDDEN,
            $status === 404 => ErrorCode::RESOURCE_NOT_FOUND,
            $status === 409 => ErrorCode::CONFLICT,
            $status === 422 => ErrorCode::VALIDATION_ERROR,
            $status === 429 => ErrorCode::TOO_MANY_REQUESTS,
            $status >= 500 => ErrorCode::SERVER_ERROR,
            default => ErrorCode::REQUEST_FAILED,
        };
    }

    /** @return array{current_page: int, last_page: int, per_page: int, total: int} */
    public static function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}
