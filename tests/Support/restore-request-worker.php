<?php

declare(strict_types=1);

use App\Backups\BackupRefused;
use App\Backups\BackupType;
use App\Backups\RestoreService;
use App\Models\Backup;
use App\Models\Website;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

$root = $argv[1] ?? '';
$directory = $argv[2] ?? '';
$index = (int) ($argv[3] ?? -1);
$websiteId = (int) ($argv[4] ?? 0);
$backupId = (int) ($argv[5] ?? 0);

if ($index < 0 || $index > 1 || ! is_dir($directory) || ! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Invalid isolated restore worker arguments.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
file_put_contents($directory.DIRECTORY_SEPARATOR.'ready-'.$index, (string) getmypid(), LOCK_EX);

$deadline = microtime(true) + 20;
while (! is_file($directory.DIRECTORY_SEPARATOR.'start') && microtime(true) < $deadline) {
    usleep(1000);
}
if (! is_file($directory.DIRECTORY_SEPARATOR.'start')) {
    fwrite(STDERR, "Restore process barrier timed out.\n");
    exit(3);
}

file_put_contents($directory.DIRECTORY_SEPARATOR.'attempting-'.$index, (string) getmypid(), LOCK_EX);
$attemptId = (string) Str::uuid();
try {
    $website = Website::query()->findOrFail($websiteId);
    $backup = Backup::query()->findOrFail($backupId);
    $restore = app(RestoreService::class)->request($website, $backup, BackupType::Full, 'test:process-'.$index, $website->host());
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, json_encode([
        'pid' => getmypid(),
        'attempt_id' => $attemptId,
        'outcome' => 'accepted',
        'restore_uuid' => $restore->uuid,
    ], JSON_THROW_ON_ERROR), LOCK_EX);
} catch (BackupRefused $exception) {
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, json_encode([
        'pid' => getmypid(),
        'attempt_id' => $attemptId,
        'outcome' => 'refused:'.$exception->errorCode,
    ], JSON_THROW_ON_ERROR), LOCK_EX);
} catch (Throwable $exception) {
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, json_encode([
        'pid' => getmypid(),
        'attempt_id' => $attemptId,
        'outcome' => 'error:'.get_class($exception),
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    fwrite(STDERR, 'Restore worker failed: '.get_class($exception)."\n");
    exit(1);
}
