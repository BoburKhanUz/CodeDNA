<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| CodeDNA serves the browser from a single origin (Nginx routes /api/* and
| /sanctum/* to Laravel), so CORS is not needed and is CLOSED by default:
| no origin is allowed and no Access-Control-Allow-Origin header is sent.
|
| Only for the documented split-origin fallback (ADR-006), list the exact
| frontend origin(s) in CORS_ALLOWED_ORIGINS (comma-separated). Wildcards are
| never used together with credentials.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-ID'],

    'exposed_headers' => ['X-Request-ID', 'Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,

];
