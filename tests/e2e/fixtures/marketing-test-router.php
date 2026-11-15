<?php

declare(strict_types=1);

// This router is used only by isolated loopback test servers. Runtime
// configuration alone cannot activate the marketing-gate test exemption.
if (PHP_SAPI !== 'cli-server'
    || getenv('APP_ENV') !== 'testing'
    || getenv('CAREGRID_DEPLOYMENT_ENV') !== 'testing') {
    http_response_code(404);
    exit;
}

if (! defined('WPCG_TEST_RUNTIME')) {
    define('WPCG_TEST_RUNTIME', true);
}

$publicRoot = realpath(dirname(__DIR__, 3).'/public');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (! is_string($publicRoot) || ! is_string($requestPath)) {
    http_response_code(404);
    exit;
}

$staticFile = realpath($publicRoot.'/'.ltrim($requestPath, '/'));
if (is_string($staticFile)
    && str_starts_with($staticFile, $publicRoot.DIRECTORY_SEPARATOR)
    && is_file($staticFile)) {
    return false;
}

require $publicRoot.'/index.php';
