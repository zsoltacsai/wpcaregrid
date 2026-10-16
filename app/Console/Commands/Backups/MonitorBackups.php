<?php

namespace App\Console\Commands\Backups;

use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Jobs\TransferBackup;
use App\Models\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Keeps backups from hanging: unfinished uploads time out, uploaded backups
 * whose transfer was lost (or abandoned mid-way by a crashed worker) are
 * handed over again, and staged data of failed backups is removed once it
 * is no longer useful.
 */
class MonitorBackups extends Command
{
    protected $signature = 'backup:monitor';

    protected $description = 'Time out stuck backups and clean up staging (run every ten minutes)';

    public function handle(BackupService $backups): int
    {
        $staleBefore = now()->subHours((int) config('backup.stale_after_hours'));

        Backup::whereIn('status', [BackupStatus::Queued, BackupStatus::Running, BackupStatus::Uploading])
            ->where('created_at', '<', $staleBefore)->each(function (Backup $backup) use ($backups) {
                $backups->fail($backup, 'BACKUP_TIMED_OUT', 'The backup did not finish in time.');
                $this->components->warn("Timed out {$backup->uuid}");
            });

        Backup::where('status', BackupStatus::Uploaded)->where('uploaded_at', '<', now()->subHour())
            ->each(fn (Backup $backup) => TransferBackup::dispatch(...TransferBackup::snapshot($backup)));

        // A transfer whose worker died (timeout, restart, crash) leaves the backup "verifying".
        // Past the job timeout no worker can still be on it: hand it over again. Objects
        // already stored are not uploaded twice, and everything is verified again.
        $abandonedBefore = now()->subSeconds((int) config('backup.transfer.timeout') + 900);
        Backup::where('status', BackupStatus::Verifying)->where('updated_at', '<', $abandonedBefore)
            ->each(function (Backup $backup) {
                $backup->transitionTo(BackupStatus::Uploaded);
                TransferBackup::dispatch(...TransferBackup::snapshot($backup));
                $this->components->warn("Transfer handed over again: {$backup->uuid}");
            });

        $keepUntil = now()->subHours((int) config('backup.staging_retention_hours'));
        Backup::whereIn('status', [BackupStatus::Failed, BackupStatus::Corrupt, BackupStatus::Expired, BackupStatus::Deleted])
            ->where('updated_at', '<', $keepUntil)->each(function (Backup $backup) {
                File::deleteDirectory(rtrim(config('backup.upload.staging_path'), '/').'/'.$backup->uuid);
            });

        return self::SUCCESS;
    }
}
