<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Guarantees a single, consistent JSON envelope across the entire API:
 *
 * {
 *     "success": true|false,
 *     "message": "human readable message",
 *     "data": {}|[]|null,
 *     "errors": {}   // only present on validation-style failures
 * }
 */
trait ApiResponseTrait
{
    protected function success(
        mixed $data = null,
        string $message = 'Request completed successfully.',
        int $statusCode = 200
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($data instanceof ResourceCollection) {
            $response = $data->response()->getData(true);

            if (isset($response['meta']) || isset($response['links'])) {
                $payload['data'] = $response['data'];
                $payload['meta'] = $response['meta'] ?? null;
                $payload['links'] = $response['links'] ?? null;
            }
        }

        return response()->json($payload, $statusCode);
    }

    protected function error(
        string $message = 'Something went wrong.',
        int $statusCode = 400,
        ?array $errors = null
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'data' => null,
        ];

        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $statusCode);
    }

    protected function created(mixed $data = null, string $message = 'Resource created successfully.'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function noContent(string $message = 'Resource deleted successfully.'): JsonResponse
    {
        return $this->success(null, $message, 200);
    }
}
