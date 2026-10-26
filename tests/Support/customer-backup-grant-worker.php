<?php

declare(strict_types=1);

use App\Backups\CustomerDownloads\BackupDownloadGrants;
use App\Models\Backup;
use App\Models\BackupObject;
use App\Models\User;
use App\Models\Website;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;

$root = $argv[1] ?? '';
$directory = $argv[2] ?? '';
$index = (int) ($argv[3] ?? -1);
$userId = (int) ($argv[4] ?? 0);
$websiteId = (int) ($argv[5] ?? 0);
$backupId = (int) ($argv[6] ?? 0);
$objectId = (int) ($argv[7] ?? 0);
if ($index < 0 || $index > 1 || ! is_dir($directory) || ! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Invalid isolated customer download worker arguments.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Queue::fake();
file_put_contents($directory.DIRECTORY_SEPARATOR.'ready-'.$index, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($directory.DIRECTORY_SEPARATOR.'start') && microtime(true) < $deadline) {
    usleep(1000);
}
if (! is_file($directory.DIRECTORY_SEPARATOR.'start')) {
    fwrite(STDERR, "Customer download grant process barrier timed out.\n");
    exit(3);
}
file_put_contents($directory.DIRECTORY_SEPARATOR.'attempting-'.$index, (string) getmypid(), LOCK_EX);

try {
    $grant = app(BackupDownloadGrants::class)->create(
        User::query()->findOrFail($userId), Website::query()->findOrFail($websiteId),
        Backup::query()->findOrFail($backupId), BackupObject::query()->findOrFail($objectId),
    );
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, json_encode(['pid' => getmypid(), 'grant_id' => $grant->id, 'status' => $grant->status], JSON_THROW_ON_ERROR), LOCK_EX);
} catch (Throwable $exception) {
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, json_encode(['pid' => getmypid(), 'error' => get_class($exception)], JSON_THROW_ON_ERROR), LOCK_EX);
    fwrite(STDERR, 'Customer download grant worker failed: '.get_class($exception)."\n");
    exit(1);
}
