<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ((getenv('CAREGRID_EXPECTED_DATABASE_DRIVER') ?: '') !== 'mysql' || (getenv('CAREGRID_EXPECTED_DATABASE_VERSION') ?: '') !== '8.4') {
    fwrite(STDERR, "CI MySQL expectation missing or unsupported.\n"); exit(1);
}
try {
    $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable $error) {
    fwrite(STDERR, "Could not establish configured Laravel CI database connection.\n"); exit(1);
}
$safe = preg_replace('/[^0-9A-Za-z._-]/', '', $version);
if ($driver !== 'mysql' || !preg_match('/^8\.4(?:\.|$)/', $version)) {
    fwrite(STDERR, "Expected PDO mysql on MySQL 8.4; received driver={$driver}, server={$safe}.\n"); exit(1);
}
fwrite(STDOUT, "Laravel PDO driver={$driver}; MySQL server={$safe}\n");
