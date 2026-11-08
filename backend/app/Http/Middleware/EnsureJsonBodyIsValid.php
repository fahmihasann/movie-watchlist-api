<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureJsonBodyIsValid
{
    /**
     * Reject malformed or non-object JSON bodies before validation runs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')
            || ! $request->isJson()
            || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $content = $request->getContent();

        if (trim($content) === '') {
            return $next($request);
        }

        $decoded = json_decode($content);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['message' => 'Malformed JSON body.'], 400);
        }

        if (! is_object($decoded)) {
            return response()->json(['message' => 'JSON body must be an object.'], 400);
        }

        return $next($request);
    }
}
