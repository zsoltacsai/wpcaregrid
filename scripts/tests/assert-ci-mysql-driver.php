<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedDriver = getenv('CAREGRID_EXPECTED_DATABASE_DRIVER') ?: '';
$expectedVersion = getenv('CAREGRID_EXPECTED_DATABASE_VERSION') ?: '';

if ($expectedDriver !== 'mysql' || $expectedVersion !== '8.4') {
    fwrite(STDERR, "CI MySQL expectation missing or unsupported.\n");
    exit(1);
}

try {
    $pdo = DB::connection()->getPdo();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable) {
    fwrite(STDERR, "Could not establish configured Laravel CI database connection.\n");
    exit(1);
}

$safeVersion = preg_replace('/[^0-9A-Za-z._-]/', '', $version) ?? '';

if ($driver !== 'mysql' || ! preg_match('/^8\\.4(?:\\.|$)/', $version)) {
    fwrite(STDERR, sprintf(
        "Expected PDO mysql on MySQL 8.4; received driver=%s, server=%s.\n",
        $driver,
        $safeVersion,
    ));
    exit(1);
}

printf("Laravel PDO driver=%s; MySQL server=%s\n", $driver, $safeVersion);
