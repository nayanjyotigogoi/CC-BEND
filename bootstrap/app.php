<?php

use App\Core\HttpError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Rate limits on login, OTP and checkout (SRS 9 security).
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every API error uses the contract shape { message, code, errors }; no HTML error pages.
        $exceptions->render(function (HttpError $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'errors' => $e->errors], $e->status);
        });
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*')) return null;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException || $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                return response()->json(['message' => 'No such endpoint', 'code' => 'no_route'], 404);
            }
            if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
                return response()->json(['message' => 'Too many tries. Please wait a minute.', 'code' => 'rate_limited'], 429);
            }
            report($e);
            return response()->json(['message' => 'Something went wrong. Please try again.', 'code' => 'server_error'] + (config('app.debug') ? ['debug' => $e->getMessage()] : []), 500);
        });
    })->create();
