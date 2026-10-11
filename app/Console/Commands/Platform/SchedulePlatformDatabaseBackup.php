<?php

namespace App\Console\Commands\Platform;

use App\Owner\OwnerSettings;
use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SchedulePlatformDatabaseBackup extends Command
{
    protected $signature = 'platform:database-backup-schedule';

    protected $description = 'Create at most the current due UTC platform backup slot.';

    public function handle(OwnerSettings $settings, PlatformDatabaseBackupService $backups): int
    {
        $configuration = $settings->group('backups');
        if (! (bool) $configuration['backups.primary.enabled']) {
            $this->info('Platform database backup scheduling is disabled.');

            return self::SUCCESS;
        }

        $frequency = (string) $configuration['backups.primary.frequency'];
        $slotStart = $this->slotStart(now('UTC'), $frequency);
        $slotKey = 'primary:'.$frequency.':'.$slotStart->timestamp;
        $fingerprint = hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        DB::table('platform_backup_schedule_slots')->insertOrIgnore([
            'slot_key' => $slotKey,
            'frequency' => $frequency,
            'settings_fingerprint' => $fingerprint,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $slot = DB::transaction(function () use ($slotKey, $frequency, $fingerprint) {
            $slot = DB::table('platform_backup_schedule_slots')->where('slot_key', $slotKey)->lockForUpdate()->first();
            if (! $slot || $slot->status === 'completed' || $slot->attempts >= 3
                || ($slot->status === 'running' && $slot->started_at && now()->diffInHours($slot->started_at) < 6)) {
                return null;
            }
            DB::table('platform_backup_schedule_slots')->where('id', $slot->id)->update([
                'frequency' => $frequency,
                'settings_fingerprint' => $fingerprint,
                'status' => 'running',
                'attempts' => (int) $slot->attempts + 1,
                'started_at' => now(),
                'completed_at' => null,
                'failure_code' => null,
                'updated_at' => now(),
            ]);

            return (int) $slot->id;
        }, attempts: 3);

        if ($slot === null) {
            $this->info('No unclaimed due platform backup slot is available.');

            return self::SUCCESS;
        }

        try {
            $backup = $backups->create($this->retentionClass($slotStart, $frequency));
            if (! $backups->verify($backup)) {
                throw new \RuntimeException('Platform backup integrity verification failed.');
            }
            DB::table('platform_backup_schedule_slots')->where('id', $slot)->update([
                'status' => 'completed', 'backup_uuid' => $backup->uuid, 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $this->info('Verified platform backup created for the current UTC slot.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            DB::table('platform_backup_schedule_slots')->where('id', $slot)->update([
                'status' => 'failed', 'failure_code' => 'PLATFORM_BACKUP_SLOT_FAILED', 'updated_at' => now(),
            ]);
            $this->error('Scheduled platform backup failed; the current slot is bounded to three attempts.');

            return self::FAILURE;
        }
    }

    private function slotStart(Carbon $now, string $frequency): Carbon
    {
        return match ($frequency) {
            '6h' => $now->copy()->startOfDay()->addHours(intdiv($now->hour, 6) * 6),
            '12h' => $now->copy()->startOfDay()->addHours(intdiv($now->hour, 12) * 12),
            'daily' => $now->copy()->startOfDay(),
            'weekly' => $now->copy()->startOfWeek(Carbon::MONDAY),
            default => throw new \RuntimeException('Unsupported persisted platform backup frequency.'),
        };
    }

    private function retentionClass(Carbon $slotStart, string $frequency): string
    {
        if ($frequency === 'weekly') {
            return 'weekly';
        }
        if ($slotStart->day === 1 && $slotStart->hour === 0) {
            return 'monthly';
        }
        if ($slotStart->isMonday() && $slotStart->hour === 0) {
            return 'weekly';
        }

        return 'daily';
    }
}
