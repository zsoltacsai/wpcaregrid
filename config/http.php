<?php

/*
|--------------------------------------------------------------------------
| HTTP security (docs/DEPLOYMENT.md)
|--------------------------------------------------------------------------
|
| Response headers added to every response by App\Http\Middleware\SecurityHeaders,
| HTTPS enforcement and trusted proxies.
|
*/

$https = str_starts_with((string) env('APP_URL', ''), 'https://');

// An empty VARIABLE= in .env counts as not set: the secure default applies.
$flag = fn (string $name, bool $default) => in_array(env($name), [null, ''], true) ? $default : (bool) env($name);

return [

    // Redirect plain HTTP to HTTPS (and generate https:// links) when APP_URL is https.
    'force_https' => $flag('HTTP_FORCE_HTTPS', $https),

    // Strict-Transport-Security on HTTPS responses. On by default in production.
    'hsts' => [
        'enabled' => $flag('HTTP_HSTS', env('APP_ENV') === 'production'),
        'max_age' => (int) env('HTTP_HSTS_MAX_AGE', 15552000),
        // Only when every subdomain of the domain is served over HTTPS.
        'include_subdomains' => (bool) env('HTTP_HSTS_INCLUDE_SUBDOMAINS', false),
    ],

    /*
    | Content-Security-Policy: "off", "report-only" (sent as
    | Content-Security-Policy-Report-Only, nothing is blocked) or "enforce".
    | Roll out as report-only on staging first and check the browser console.
    | Alpine.js needs 'unsafe-eval'; the one inline script carries a nonce;
    | admin access posts a form to the customer's own site, hence form-action https:.
    */
    'csp' => [
        'mode' => env('HTTP_CSP', 'off'),
        'report_uri' => env('HTTP_CSP_REPORT_URI'),
    ],

    // Load balancers / reverse proxies whose X-Forwarded-* headers are trusted:
    // a comma-separated list of IPs/CIDRs, or "*" when the app is only reachable through the proxy.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

];
