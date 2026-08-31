<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Loaded under the "api/v1" prefix with the "api" middleware group,
| as registered in bootstrap/app.php. This file will grow to include
| auth/, admin/, provider/, customer/, and public/ route groups as
| each module is built.
|
*/

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'pong',
        'data' => [
            'timestamp' => now()->toIso8601String(),
        ],
    ]);
})->name('api.v1.ping');
