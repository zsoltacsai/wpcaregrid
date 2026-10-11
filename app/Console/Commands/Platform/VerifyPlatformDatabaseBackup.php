<?php

namespace App\Console\Commands\Platform;

use App\Models\PlatformDatabaseBackup;
use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use Illuminate\Console\Command;

final class VerifyPlatformDatabaseBackup extends Command
{
    protected $signature = 'platform:database-backup-verify {uuid}';

    protected $description = 'Verify the stored bytes and compressed-stream integrity of a platform database backup.';

    public function handle(PlatformDatabaseBackupService $backups): int
    {
        $backup = PlatformDatabaseBackup::query()->where('uuid', $this->argument('uuid'))->first();
        if (! $backup) {
            $this->error('Platform database backup was not found.');

            return self::FAILURE;
        }

        if (! $backups->verify($backup)) {
            $this->error("Platform database backup {$backup->uuid} failed verification.");

            return self::FAILURE;
        }
        $this->info("Platform database backup verified: {$backup->uuid}");

        return self::SUCCESS;
    }
}
