<?php

declare(strict_types=1);

use Illuminate\\Contracts\\Console\\Kernel;
use Illuminate\\Support\\Facades\\DB;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expected = getenv('CAREGRID_EXPECTED_DATABASE_DRIVER') ?: '';
$configured = (string) config('database.default');
$connection = DB::connection();
$laravelDriver = $connection->getDriverName();
$pdoDriver = (string) $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($expected !== 'sqlite'
    || $configured !== $expected
    || $laravelDriver !== $expected
    || $pdoDriver !== $expected) {
    fwrite(STDERR, sprintf(
        "Expected SQLite; expected=%s configured=%s laravel=%s pdo=%s\n",
        $expected ?: 'unset',
        $configured,
        $laravelDriver,
        $pdoDriver,
    ));
    exit(1);
}

$version = DB::selectOne('select sqlite_version() as version')->version ?? null;

if (!is_string($version) || !preg_match('/^\\d+\\.\\d+(?:\\.\\d+)?$/', $version)) {
    fwrite(STDERR, "SQLite engine version could not be verified.\n");
    exit(1);
}

printf("DATABASE_ENGINE=sqlite\nSQLITE_VERSION=%s\n", $version);
