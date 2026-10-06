<?php

/*
|--------------------------------------------------------------------------
| CodeDNA application settings
|--------------------------------------------------------------------------
|
| Application-specific configuration. Application code reads these values
| through config('codedna.*'); env() is only ever called in config files.
|
*/

return [

    // Identifier reported by GET /api/v1/health.
    'service' => 'codedna-api',

    // Deployed build identifier (e.g. a release tag or git SHA), set by the
    // deployment. "dev" for local development.
    'version' => env('APP_VERSION', 'dev'),

    // Current public API version (routes are served under /api/{api_version}).
    'api_version' => 'v1',

    // Proxies whose X-Forwarded-* headers are trusted. Locally, Nginx reaches
    // PHP-FPM over the private Docker network. Comma-separated IPs/CIDRs.
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')),
    ))),

    // Request rate limits (see docs/api/README.md#rate-limiting).
    'rate_limits' => [
        // Every /api/v1 route, per authenticated user or per IP.
        'api_per_minute' => 120,
        // POST /api/v1/auth/login, per email+IP pair and per IP.
        'login_per_minute_per_email' => 5,
        'login_per_minute_per_ip' => 20,
        // POST /api/v1/auth/register, per IP.
        'register_per_minute_per_ip' => 10,
        // PATCH /api/v1/profile, per user.
        'profile_update_per_minute' => 30,
        // PATCH /api/v1/auth/password, per user (limits current-password guessing).
        'password_change_per_minute' => 5,
        'password_change_per_hour' => 20,
    ],

];
