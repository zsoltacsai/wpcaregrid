<?php

namespace App\Console\Commands\Privacy;

use App\Models\PlatformDatabaseBackup;
use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use App\Privacy\PrivacyTombstoneReplayApplier;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

final class ReplayPrivacyTombstones extends Command
{
    protected $signature = 'privacy:restore-replay {recovery_id : Opaque UUID for this recovery attempt} {--snapshot-watermark= : Watermark recorded in the verified backup manifest} {--source-backup= : Verified platform backup UUID} {--confirm-workers-stopped : Confirm mutation-capable workers and scheduler are stopped}';

    protected $description = 'Replay verified independent privacy suppression events after a restored snapshot; keeps quarantine active on any blocker';

    public function handle(PrivacyTombstoneReplayApplier $applier): int
    {
        $recoveryId = (string) $this->argument('recovery_id');
        $watermark = $this->option('snapshot-watermark');
        $sourceBackup = (string) $this->option('source-backup');
        if (! Str::isUuid($recoveryId) || ! Str::isUuid($sourceBackup)) {
            $this->error('Refused: recovery and source-backup references must be opaque UUIDs.');

            return self::FAILURE;
        }
        if (! $this->option('confirm-workers-stopped')) {
            $this->error('Refused: stop and verify all mutation-capable workers and scheduler before replay.');

            return self::FAILURE;
        }
        if (! config('platform.recovery_mode')) {
            $this->error('Refused: PLATFORM_RECOVERY_MODE must remain enabled during replay.');

            return self::FAILURE;
        }
        if (config('platform.recovery_id') !== $recoveryId
            || config('platform.recovery_source_backup_uuid') !== $sourceBackup
            || config('platform.recovery_snapshot_watermark') !== $watermark) {
            $this->error('Refused: recovery ID, backup UUID, and watermark must match the out-of-band active recovery context.');

            return self::FAILURE;
        }
        if ($watermark !== null && ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', (string) $watermark)) {
            $this->error('Refused: snapshot watermark is invalid or unsupported.');

            return self::FAILURE;
        }

        try {
            $backup = PlatformDatabaseBackup::query()->where('uuid', $sourceBackup)->firstOrFail();
            $verifiedWatermark = app(PlatformDatabaseBackupService::class)->recoverySnapshotWatermark($backup);
            if ($verifiedWatermark !== ($watermark === null ? null : (string) $watermark)) {
                throw new RuntimeException('privacy_restore_watermark_mismatch');
            }
            $session = $applier->replay($recoveryId, $verifiedWatermark, null, $sourceBackup);
        } catch (RuntimeException $exception) {
            $this->error('Privacy replay failed closed: '.preg_replace('/[^a-z0-9_]/', '', $exception->getMessage()));

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['session', $session->uuid],
            ['status', $session->status],
            ['verification', $session->verification_status],
            ['snapshot_watermark', $session->snapshot_watermark ?? 'empty'],
            ['ending_watermark', $session->ending_watermark ?? 'empty'],
            ['events_discovered', $session->events_discovered],
            ['events_applied', $session->events_applied],
            ['events_already_applied', $session->events_already_applied],
            ['events_blocked', $session->events_blocked],
            ['quarantine_release', 'not performed by this command'],
        ]);

        return $session->status === 'complete' && $session->verification_status === 'verified' ? self::SUCCESS : self::FAILURE;
    }
}
