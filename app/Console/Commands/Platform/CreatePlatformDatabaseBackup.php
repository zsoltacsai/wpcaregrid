<?php

namespace App\Console\Commands\Platform;

use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use Illuminate\Console\Command;

final class CreatePlatformDatabaseBackup extends Command
{
    protected $signature = 'platform:database-backup {--retention=daily}';

    protected $description = 'Create and verify a platform database backup artifact.';

    public function handle(PlatformDatabaseBackupService $backups): int
    {
        try {
            $backup = $backups->createManual((string) $this->option('retention'));
            if (! $backups->verify($backup)) {
                $this->error("Platform backup {$backup->uuid} failed integrity verification.");

                return self::FAILURE;
            }
            $this->info("Platform database backup verified: {$backup->uuid}");

            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Platform database backup failed. Review the structured application log; secrets are not printed.');

            return self::FAILURE;
        }
    }
}
