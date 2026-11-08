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
            return $this->rejection($request);
        }

        if ($request->isJson()) {
            $decoded = json_decode((string) $request->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE && $this->containsNullByte($decoded)) {
                return $this->rejection($request);
            }
        }

        return $next($request);
    }

    /**
     * Reject before HandleCors runs, so this response must carry the CORS
     * headers itself for the browser to expose the JSON body.
     */
    private function rejection(Request $request): Response
    {
        $response = response()->json(['message' => 'The request must not contain null bytes.'], 400);

        $origins = (array) config('cors.allowed_origins', ['*']);
        $origin = $request->headers->get('Origin');

        if (in_array('*', $origins, true)) {
            if ($origin !== null) {
                $response->headers->set('Access-Control-Allow-Origin', '*');
            }

            return $response;
        }

        $response->setVary('Origin', false);

        if ($origin !== null && in_array($origin, $origins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        return $response;
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
