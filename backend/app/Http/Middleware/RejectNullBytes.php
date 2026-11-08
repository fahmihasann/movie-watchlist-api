<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectNullBytes
{
    /**
     * Reject null bytes before TrimStrings can silently strip them from edges.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        if ($this->containsNullByte($request->query->all())) {
            return response()->json(['message' => 'The request must not contain null bytes.'], 400);
        }

        if ($request->isJson()) {
            $decoded = json_decode((string) $request->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE && $this->containsNullByte($decoded)) {
                return response()->json(['message' => 'The request must not contain null bytes.'], 400);
            }
        }

        return $next($request);
    }

    private function containsNullByte(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, "\0");
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsNullByte($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
