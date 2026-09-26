<?php

/*
|--------------------------------------------------------------------------
| Secure WordPress admin access (docs/ADMIN_ACCESS.md)
|--------------------------------------------------------------------------
*/

return [

    // The one-time browser token must reach the site within this many seconds.
    'token_ttl' => (int) env('ADMIN_ACCESS_TOKEN_TTL', 120),

    // Session lifetimes in minutes. The connector also caps them (60 / 30).
    'durations' => [
        'maintenance' => (int) env('ADMIN_ACCESS_MAINTENANCE_MINUTES', 30),
        'full_admin' => (int) env('ADMIN_ACCESS_FULL_ADMIN_MINUTES', 15),
    ],

    // Wrong tokens for one request before it is failed.
    'max_failed_attempts' => 5,

    // New requests per website per hour (on top of the route rate limits).
    'max_requests_per_hour' => (int) env('ADMIN_ACCESS_MAX_REQUESTS_PER_HOUR', 10),

    // Operators may request full admin only when this is enabled AND they hold the
    // admin_access.full permission. Off by default: full admin stays customer-only.
    'operator_full_admin' => (bool) env('ADMIN_ACCESS_OPERATOR_FULL_ADMIN', false),

    // Access levels each plan allows. Full admin is never the default action.
    'plans' => collect(['wordpress', 'woocommerce'])->mapWithKeys(fn (string $product) => [
        $product => collect(['basic', 'business', 'secure'])->mapWithKeys(fn (string $tier) => [
            $tier => ['maintenance' => true, 'full_admin' => (bool) env('ADMIN_ACCESS_FULL_ADMIN_ALLOWED', true)],
        ])->all(),
    ])->all(),

];
