<?php

declare(strict_types=1);

use App\Backups\CustomerDownloads\BackupDownloadGrants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

$root = $argv[1] ?? '';
$directory = $argv[2] ?? '';
$grantId = (int) ($argv[3] ?? 0);
if ($grantId < 1 || ! is_dir($directory) || ! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Invalid isolated grant-failure worker arguments.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::connection()->getPdo();
file_put_contents($directory.DIRECTORY_SEPARATOR.'connection-id', (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, LOCK_EX);
file_put_contents($directory.DIRECTORY_SEPARATOR.'ready', 'ready', LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($directory.DIRECTORY_SEPARATOR.'start') && microtime(true) < $deadline) {
    usleep(1000);
}
if (! is_file($directory.DIRECTORY_SEPARATOR.'start')) {
    fwrite(STDERR, "Grant-failure worker barrier timed out.\n");
    exit(3);
}
file_put_contents($directory.DIRECTORY_SEPARATOR.'attempting', 'attempting', LOCK_EX);
DB::listen(static function (QueryExecuted $query) use ($directory): void {
    if (preg_match('/^\s*select\b/i', $query->sql) === 1 && str_contains(strtolower($query->sql), 'backup_download_grants')) {
        file_put_contents($directory.DIRECTORY_SEPARATOR.'selected', 'selected', LOCK_EX);
    }
});

try {
    app(BackupDownloadGrants::class)->failPreparation($grantId, 'OBJECT_INTEGRITY_MISMATCH');
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result', 'completed', LOCK_EX);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Grant-failure worker error: '.get_class($exception)."\n");
    exit(1);
}
