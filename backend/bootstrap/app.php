<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureJsonBodyIsValid;
use App\Http\Middleware\RejectNullBytes;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->prepend(RejectNullBytes::class);
        $middleware->append(EnsureJsonBodyIsValid::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'token',
            'authorization',
            'api_key',
            'gemini_api_key',
        ]);

        $exceptions->render(
            fn (Throwable $exception, Request $request) => app(ApiExceptionRenderer::class)
                ->render($exception, $request),
        );

        $exceptions->report(
            fn (Throwable $exception) => app(ApiExceptionRenderer::class)
                ->report($exception),
        );
    })->create();
