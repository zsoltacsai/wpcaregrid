<?php

declare(strict_types=1);

$username = getenv('WPCG_TEST_HTTP_AUTH_USER');
$password = getenv('WPCG_TEST_HTTP_AUTH_PASSWORD');
$expectedAuthorization = is_string($username) && $username !== '' && is_string($password) && $password !== ''
    ? 'Basic '.base64_encode($username.':'.$password)
    : '';
if ($expectedAuthorization === ''
    || ! hash_equals($expectedAuthorization, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="staging-next-e2e"');
    exit;
}

$current = getenv('WPCG_CURRENT_APP');
if (! is_string($current) || $current === '' || ! is_link($current)) {
    http_response_code(503);
    exit;
}

$entry = $current.'/public/index.php';
if (! is_file($entry) || is_link($entry)) {
    http_response_code(503);
    exit;
}

require $entry;
