<?php

namespace App\Console\Commands\Platform;

use App\Events\Platform\PlatformRecoverySignal;
use App\Models\PlatformDatabaseBackup;
use App\Platform\DisasterRecovery\PlatformBackupCryptography;
use App\Platform\DisasterRecovery\PlatformBackupStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ReconcilePlatformDatabaseBackups extends Command
{
    protected $signature = 'platform:database-backup-reconcile';

    protected $description = 'Read-only reconciliation of platform database backup records and stored artifacts.';

    public function handle(PlatformBackupCryptography $cryptography, PlatformBackupStorage $platformStorage): int
    {
        $report = ['generated_at' => now()->toIso8601String(), 'mode' => 'read-only', 'disks' => [], 'anomalies' => []];
        $diskNames = PlatformDatabaseBackup::query()->distinct()->pluck('storage_disk')
            ->push((string) config('platform_backup.disk'))
            ->filter()
            ->unique()
            ->values();

        foreach ($diskNames as $diskName) {
            try {
                $disk = $diskName === 'platform_backups' ? $platformStorage->disk() : Storage::disk($diskName);
                $prefix = trim((string) config('platform_backup.prefix'), '/');
                $listed = array_fill_keys($disk->allFiles($prefix), true);
                $expected = [];
                $anomalies = [];

                PlatformDatabaseBackup::query()->where('storage_disk', $diskName)->with('deliveries')->orderBy('id')->chunkById(100, function ($backups) use ($disk, $cryptography, &$expected, &$anomalies): void {
                    foreach ($backups as $backup) {
                        $anomalyCountBeforeBackup = count($anomalies);
                        $delivery = $backup->deliveries->firstWhere('destination_key', 'primary');
                        if (! $delivery) {
                            $anomalies[] = ['type' => 'destination_delivery_missing', 'backup_uuid' => $backup->uuid];
                        } elseif ($delivery->storage_disk !== $backup->storage_disk
                            || $delivery->object_key !== $backup->object_key
                            || $delivery->manifest_object_key !== $backup->manifest_object_key
                            || $delivery->sha256 !== $backup->sha256) {
                            $anomalies[] = ['type' => 'destination_delivery_metadata_mismatch', 'backup_uuid' => $backup->uuid];
                        }
                        $expected[$backup->object_key] = true;
                        $expected[$backup->manifest_object_key] = true;

                        if ($backup->status === 'creating') {
                            $anomalies[] = ['type' => 'stale_pending_upload', 'backup_uuid' => $backup->uuid];
                        }
                        if ($backup->status === 'stored') {
                            $anomalies[] = ['type' => 'verification_pending', 'backup_uuid' => $backup->uuid];
                        }
                        if ($backup->status === 'verification_failed') {
                            $anomalies[] = ['type' => 'verification_failed', 'backup_uuid' => $backup->uuid];
                        }
                        if ($backup->status === 'integrity_verified') {
                            $anomalies[] = ['type' => 'disposable_import_verification_required', 'backup_uuid' => $backup->uuid];
                        }
                        if ($backup->status === 'failed' && ($disk->exists($backup->object_key) || $disk->exists($backup->manifest_object_key))) {
                            $anomalies[] = ['type' => 'failed_backup_artifact_retained', 'backup_uuid' => $backup->uuid];
                        }
                        if (! $disk->exists($backup->object_key)) {
                            $anomalies[] = ['type' => 'metadata_missing_object', 'backup_uuid' => $backup->uuid];
                            $delivery?->forceFill(['reconciliation_status' => 'reconciliation_required', 'last_reconciled_at' => now()])->save();

                            continue;
                        }
                        if (! $disk->exists($backup->manifest_object_key)) {
                            $anomalies[] = ['type' => 'metadata_missing_manifest', 'backup_uuid' => $backup->uuid];
                            $delivery?->forceFill(['reconciliation_status' => 'reconciliation_required', 'last_reconciled_at' => now()])->save();

                            continue;
                        }

                        try {
                            $manifest = json_decode($disk->get($backup->manifest_object_key), true, flags: JSON_THROW_ON_ERROR);
                            $stream = $disk->readStream($backup->object_key);
                            if (! is_resource($stream)) {
                                throw new \RuntimeException('Stored object could not be opened for reconciliation.');
                            }
                            $hash = hash_init('sha256');
                            try {
                                if (hash_update_stream($hash, $stream) === false) {
                                    throw new \RuntimeException('Stored object stream could not be hashed.');
                                }
                            } finally {
                                fclose($stream);
                            }
                            $actualHash = hash_final($hash);
                            $actualSize = $disk->size($backup->object_key);
                        } catch (Throwable) {
                            $anomalies[] = ['type' => 'stored_artifact_unreadable', 'backup_uuid' => $backup->uuid];
                            $delivery?->forceFill(['reconciliation_status' => 'reconciliation_required', 'last_reconciled_at' => now()])->save();

                            continue;
                        }

                        if (! is_array($manifest)
                            || ($manifest['backup_uuid'] ?? null) !== $backup->uuid
                            || ($manifest['object_key'] ?? null) !== $backup->object_key
                            || ($manifest['sha256'] ?? null) !== $backup->sha256
                            || (int) ($manifest['size_bytes'] ?? 0) !== (int) $backup->size_bytes) {
                            $anomalies[] = ['type' => 'manifest_metadata_mismatch', 'backup_uuid' => $backup->uuid];
                        }
                        if (is_array($manifest['encryption'] ?? null) && ! $cryptography->verifyManifest($manifest)) {
                            $anomalies[] = ['type' => 'manifest_authentication_failed', 'backup_uuid' => $backup->uuid];
                        }
                        if ($actualHash === null || ! is_string($backup->sha256) || ! hash_equals($backup->sha256, $actualHash)
                            || (int) $actualSize !== (int) $backup->size_bytes) {
                            $anomalies[] = ['type' => 'artifact_integrity_mismatch', 'backup_uuid' => $backup->uuid];
                        }
                        if ($backup->status === 'verified' && $backup->verification_status !== 'verified') {
                            $anomalies[] = ['type' => 'verification_state_mismatch', 'backup_uuid' => $backup->uuid];
                        }
                        if ($delivery) {
                            $delivery->forceFill([
                                'reconciliation_status' => count($anomalies) === $anomalyCountBeforeBackup ? 'healthy' : 'reconciliation_required',
                                'last_reconciled_at' => now(),
                            ])->save();
                        }
                    }
                });

                foreach (array_diff_key($listed, $expected) as $key => $_) {
                    $anomalies[] = ['type' => 'orphan_object', 'object_key' => $key];
                }

                $report['disks'][$diskName] = ['state' => $anomalies === [] ? 'healthy' : 'reconciliation_required', 'listed_objects' => count($listed)];
                array_push($report['anomalies'], ...$anomalies);
            } catch (Throwable $error) {
                report($error);
                $report['disks'][$diskName] = ['state' => 'unavailable', 'reason' => 'provider_read_failed'];
                $report['anomalies'][] = ['type' => 'storage_unavailable', 'disk' => $diskName];
                event(new PlatformRecoverySignal(PlatformRecoverySignal::STORAGE_UNAVAILABLE, ['operation' => 'platform_backup_reconciliation']));
            }
        }

        foreach ($report['anomalies'] as $anomaly) {
            event(new PlatformRecoverySignal(PlatformRecoverySignal::STORAGE_RECONCILIATION_ANOMALY, ['type' => $anomaly['type']]));
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['anomalies'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
