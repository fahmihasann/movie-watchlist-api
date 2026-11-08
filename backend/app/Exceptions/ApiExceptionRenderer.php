<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Single place that turns every exception raised under api/* into the
 * documented error shape: { "message": "...", "errors"?: { ... } }.
 */
class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof HttpResponseException) {
            return $exception->getResponse();
        }

        if ($exception instanceof ValidationException) {
            $errors = $exception->errors();
            $firstMessage = collect($errors)->flatten()->first();

            return $this->json($firstMessage ?? $exception->getMessage(), 400, errors: $errors);
        }

        if ($exception instanceof AuthenticationException) {
            return $this->json('Unauthenticated.', 401);
        }

        if ($exception instanceof AuthorizationException
            || $exception instanceof AccessDeniedHttpException) {
            return $this->json('Forbidden.', 403);
        }

        if ($exception instanceof ModelNotFoundException
            || $exception instanceof NotFoundHttpException) {
            return $this->json('Not found.', 404);
        }

        if ($exception instanceof MethodNotAllowedHttpException) {
            return $this->json('Method not allowed.', 405, $exception->getHeaders());
        }

        if ($exception instanceof PostTooLargeException) {
            return $this->json('Payload too large.', 413, $exception->getHeaders());
        }

        if ($exception instanceof ThrottleRequestsException) {
            return $this->json(
                'Too many requests. Please try again later.',
                429,
                $exception->getHeaders(),
            );
        }

        if ($exception instanceof DuplicateUserException) {
            return $this->json(
                $exception->getMessage(),
                409,
                errors: $exception->field === null
                    ? (object) []
                    : [$exception->field => [$exception->getMessage()]],
            );
        }

        if ($exception instanceof ApiException) {
            return $this->json($exception->getMessage(), $exception->status);
        }

        if ($exception instanceof QueryException) {
            return $this->fromQueryException($exception);
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->json(
                $this->genericMessage($exception->getStatusCode()),
                $exception->getStatusCode(),
                $exception->getHeaders(),
            );
        }

        return $this->json('Server error.', 500);
    }

    /**
     * Decide whether an exception is logged, and log it without request input,
     * headers, SQL bindings, or other sensitive values.
     */
    public function report(Throwable $exception): ?bool
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        if (! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof DuplicateUserException) {
            return false;
        }

        if ($exception instanceof ApiException) {
            if ($exception->status >= 500) {
                $this->logException('API domain exception.', $request, $exception);
            }

            return false;
        }

        if ($exception instanceof QueryException) {
            $this->logException('Database exception while handling API request.', $request, $exception, [
                'sql_state' => $exception->errorInfo[0] ?? null,
                'driver_code' => $exception->errorInfo[1] ?? null,
            ]);

            return false;
        }

        if ($exception instanceof HttpExceptionInterface
            || $exception instanceof ValidationException
            || $exception instanceof AuthenticationException
            || $exception instanceof HttpResponseException) {
            return null;
        }

        $this->logException('Unhandled exception while handling API request.', $request, $exception);

        return false;
    }

    private function fromQueryException(QueryException $exception): Response
    {
        return match ((int) ($exception->errorInfo[1] ?? 0)) {
            1062 => $this->json('Resource already exists.', 409),
            1451 => $this->json('Resource is still referenced.', 409),
            1452 => $this->json('Related resource not found.', 404),
            default => $this->json('Server error.', 500),
        };
    }

    private function genericMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            409 => 'Conflict.',
            413 => 'Payload too large.',
            415 => 'Unsupported media type.',
            419 => 'Page expired.',
            422 => 'Unprocessable entity.',
            429 => 'Too many requests. Please try again later.',
            500 => 'Server error.',
            502, 503, 504 => 'Service unavailable.',
            default => 'Request failed.',
        };
    }

    private function json(
        string $message,
        int $status,
        array $headers = [],
        array|object|null $errors = null,
    ): Response {
        $payload = ['message' => $message];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status, $headers);
    }

    private function logException(
        string $message,
        Request $request,
        Throwable $exception,
        array $extra = [],
    ): void {
        Log::error($message, array_merge([
            'method' => $request->method(),
            'path' => $request->path(),
            'exception' => $exception::class,
        ], $extra));
    }
}
