<?php

/**
 * HTTP smoke test against a running server (CI, staging, a fresh deployment):
 *
 *   php tests/smoke/http-smoke.php https://staging.example
 *
 * Read-only: GET requests to public and login pages only, no sign-in, no
 * data created. Checks status codes, that the customer, operator and owner
 * areas are closed to anonymous visitors, the security headers, and that
 * no translation key or stack trace reaches a page. Exit code 1 on failure.
 */
$base = rtrim($argv[1] ?? '', '/');
if (! preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "Usage: php tests/smoke/http-smoke.php https://your-host\n");
    exit(2);
}

$failures = [];
$checks = 0;

$get = function (string $path) use ($base): array {
    $context = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20, 'header' => "Accept: text/html\r\n"]]);
    $body = @file_get_contents($base.$path, false, $context);
    $headers = [];
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return [$status, $headers, (string) $body];
};

$expect = function (bool $ok, string $what) use (&$failures, &$checks): void {
    $checks++;
    if (! $ok) {
        $failures[] = $what;
    }
};

// Legacy marketing paths permanently redirect to the canonical English URLs.
foreach (['/' => '/en/', '/pricing' => '/en/pricing'] as $path => $destination) {
    [$status, $headers] = $get($path);
    $expectedLocation = $base.$destination;
    $expect($status === 301 && ($headers['location'] ?? '') === $expectedLocation, "{$path} permanently redirects to {$expectedLocation} (got {$status} ".($headers['location'] ?? '-').')');
}

// Localized marketing pages and sign-in pages render directly.
$publicPages = [
    '/en/' => ['WP CareGrid', 'en'],
    '/hu/' => ['WP CareGrid', 'hu'],
    '/en/pricing' => ['WP CareGrid', 'en'],
    '/hu/pricing' => ['WP CareGrid', 'hu'],
    '/login' => ['password', null],
    '/operator/login' => ['password', null],
    '/owner/login' => ['password', null],
    '/forgot-password' => ['email', null],
];
foreach ($publicPages as $path => [$marker, $locale]) {
    [$status, $headers, $body] = $get($path);
    $expect($status === 200, "{$path} returns 200 (got {$status})");
    $expect(stripos($body, $marker) !== false, "{$path} contains \"{$marker}\"");
    if ($locale !== null) {
        $expect(($headers['content-language'] ?? '') === $locale, "{$path} sends Content-Language: {$locale}");
    }
    $expect(! preg_match('/>\s*(home|pricing|owner|operator|account|content|security|checkout)\.[a-z_]+(\.[a-z_]+)+\s*</', $body), "{$path} shows no translation keys");
    $expect(stripos($body, 'Stack trace') === false && stripos($body, 'Whoops') === false, "{$path} shows no error details");
    $expect(($headers['x-content-type-options'] ?? '') === 'nosniff', "{$path} sends X-Content-Type-Options: nosniff");
    $expect(in_array(strtoupper($headers['x-frame-options'] ?? ''), ['DENY', 'SAMEORIGIN'], true), "{$path} sends X-Frame-Options");
    $expect(isset($headers['referrer-policy']), "{$path} sends Referrer-Policy");
    if (str_starts_with($base, 'https://')) {
        $expect(isset($headers['strict-transport-security']), "{$path} sends Strict-Transport-Security over HTTPS");
    }
}

// Both localized homepages have one h1 and their pricing section.
foreach (['/en/', '/hu/'] as $path) {
    [, , $home] = $get($path);
    $expect(substr_count($home, '<h1') === 1, "{$path} has exactly one h1");
    $expect(str_contains($home, 'id="pricing"'), "{$path} has the pricing section");
}

// Protected areas send anonymous visitors to their own sign-in page.
foreach (['/account' => '/login', '/account/websites' => '/login', '/operator' => '/operator/login', '/operator/security' => '/operator/login', '/owner' => '/owner/login', '/owner/system' => '/owner/login', '/owner/customers' => '/owner/login'] as $path => $login) {
    [$status, $headers] = $get($path);
    $expect(in_array($status, [301, 302, 303], true) && str_ends_with(parse_url($headers['location'] ?? '', PHP_URL_PATH) ?? '', $login), "{$path} redirects anonymous visitors to {$login} (got {$status} ".($headers['location'] ?? '-').')');
}

// Draft preview needs a signed link (and an owner session).
[$status, $headers] = $get('/owner/preview/en');
$expect(in_array($status, [302, 403], true), "/owner/preview/en without signature is refused (got {$status})");

// Connector API refuses anonymous requests with a JSON error, not a page.
[$status, $headers, $body] = $get('/api/v1/connector/jobs');
$expect(in_array($status, [401, 403], true), "connector API refuses anonymous requests (got {$status})");
$expect(str_contains($body, '"error"'), 'connector API answers with a JSON error');

// Health endpoint for load balancers.
[$status] = $get('/up');
$expect($status === 200, "/up returns 200 (got {$status})");

if ($failures !== []) {
    fwrite(STDERR, 'HTTP smoke test failed ('.count($failures)." of {$checks} checks):\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "HTTP smoke test passed: {$checks} checks against {$base}\n");
