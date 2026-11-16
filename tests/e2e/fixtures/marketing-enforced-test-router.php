<?php

declare(strict_types=1);

// This isolated router exercises the production enforcement path. It must
// never define WPCG_TEST_RUNTIME; only the in-process PHPUnit/test-router
// fixtures may activate that exemption.
if (PHP_SAPI !== 'cli-server'
    || getenv('APP_ENV') !== 'testing'
    || getenv('CAREGRID_DEPLOYMENT_ENV') !== 'testing'
    || getenv('MARKETING_RELEASE_ENFORCEMENT_MODE') !== 'enforced'
    || defined('WPCG_TEST_RUNTIME')) {
    http_response_code(404);
    exit;
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
