<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The companion frontend runs on its own origin (for example
| http://localhost:3000 or http://localhost:5500) and authenticates with
| the Authorization header instead of cookies, so credentials stay off.
|
| CORS_ALLOWED_ORIGINS accepts a comma-separated list of exact origins.
| An absent or empty value falls back to "*" for local development.
|
*/

$allowedOrigins = array_values(array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))),
    fn (string $origin): bool => $origin !== '',
));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'DELETE'],

    'allowed_origins' => $allowedOrigins === [] ? ['*'] : $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-Total-Count',
        'X-Total-Pages',
        'X-Page',
        'X-Per-Page',
        'Retry-After',
    ],

    'max_age' => 600,

    'supports_credentials' => false,

];
