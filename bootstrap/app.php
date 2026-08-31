<?php

use App\Traits\ApiResponseTrait;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Route::prefix('api/v1')
                ->middleware('api')
                ->group(base_path('routes/api_v1.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $responder = new class {
            use ApiResponseTrait;

            public function jsonSuccess(mixed $data = null, string $message = 'OK', int $code = 200)
            {
                return $this->success($data, $message, $code);
            }

            public function jsonError(string $message, int $code = 400, ?array $errors = null)
            {
                return $this->error($message, $code, $errors);
            }
        };

        $exceptions->render(function (ValidationException $e, Request $request) use ($responder) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $responder->jsonError('The given data was invalid.', 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($responder) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $responder->jsonError('Unauthenticated.', 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($responder) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $responder->jsonError($e->getMessage() ?: 'This action is unauthorized.', 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($responder) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $responder->jsonError('Resource not found.', 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($responder) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $responder->jsonError('Endpoint not found.', 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($responder) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $responder->jsonError($e->getMessage() ?: 'Request failed.', $e->getStatusCode());
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) use ($responder) {
            if (($request->is('api/*') || $request->expectsJson()) && ! app()->hasDebugModeEnabled()) {
                return $responder->jsonError('An unexpected error occurred.', 500);
            }
        });
    })->create();
