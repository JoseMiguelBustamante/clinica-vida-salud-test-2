<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: [
            'pagos/stripe/webhook',
        ]);
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('auth/login', 'login', 'usuarios', 'usuarios/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('auth/login', 'login')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'auth/csrf', 'auth/login', 'auth/session', 'auth/logout') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('auth/csrf', 'auth/login', 'auth/session', 'auth/logout')) {
                return null;
            }
            if ($exception instanceof ValidationException) {
                return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
            }
            if ($exception instanceof HttpExceptionInterface
                && $exception->getStatusCode() < 500) {
                $status = $exception->getStatusCode();

                return response()->json(['message' => $status === 419 ? 'CSRF token mismatch.' : 'Request rejected.'], $status);
            }

            return response()->json(['message' => $request->is('auth/logout')
                ? 'Unable to complete sign out. Please try again.'
                : 'Unable to complete authentication. Please try again.'], 503);
        });
        // Authentication failures must not leak SQL parameters through debug reports.
        $exceptions->report(function (Throwable $exception) {
            return request()->is('auth/csrf', 'auth/login', 'auth/session', 'auth/logout') ? false : null;
        });
    })->create();
