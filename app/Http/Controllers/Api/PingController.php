<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Liveness probe for API clients and uptime monitors: confirms the API
 * answers and returns the server time. Touches no database or cache.
 */
class PingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'pong',
            'data' => ['timestamp' => now()->toIso8601String()],
        ]);
    }
}
