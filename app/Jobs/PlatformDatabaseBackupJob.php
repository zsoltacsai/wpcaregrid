<?php

namespace App\Jobs;

use App\Events\Platform\PlatformRecoverySignal;
use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class PlatformDatabaseBackupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3900;

    public int $uniqueFor = 14400;

    public function uniqueId(): string
    {
        return 'platform-database-manual';
    }

    public function handle(PlatformDatabaseBackupService $backups): void
    {
        $backup = $backups->create('daily');
        if (! $backups->verify($backup)) {
            event(new PlatformRecoverySignal('platform_db_backup_verification_failed', ['backup_uuid' => $backup->uuid, 'reason' => 'manual_verification_failed']));
            throw new RuntimeException('Manual platform database backup failed verification.');
        }
    }
}
