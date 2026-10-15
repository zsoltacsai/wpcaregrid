<?php

namespace App\Console\Commands\Backups;

use App\Backups\Storage\IncompleteUploadListable;
use App\Backups\Storage\ListableBackupStorage;
use App\Backups\Storage\ObjectKey;
use App\Backups\Storage\StorageManager;
use App\Events\Platform\PlatformRecoverySignal;
use App\Models\Backup;
use App\Models\BackupObject;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileBackupStorage extends Command
{
    protected $signature = 'backup:reconcile-storage';

    protected $description = 'Report backup metadata/object divergence without deleting or modifying data.';

    public function handle(StorageManager $storages): int
    {
        $report = ['generated_at' => now()->toIso8601String(), 'mode' => 'read-only', 'providers' => [], 'anomalies' => []];
        $byProvider = Backup::query()->whereNotNull('storage_provider')->distinct()->pluck('storage_provider');

        foreach ($byProvider as $providerName) {
            try {
                $storage = $storages->provider($providerName);
                if (! $storage instanceof ListableBackupStorage) {
                    $report['providers'][$providerName] = ['state' => 'reconciliation_required', 'reason' => 'listing_unsupported'];

                    continue;
                }
                $remoteKeys = array_fill_keys(iterator_to_array((function () use ($storage) {
                    yield from $storage->listKeys();
                })()), true);
                $expectedKeys = [];
                $providerAnomalies = [];
                Backup::query()->with(['website', 'objects', 'objectDeletions'])->where('storage_provider', $providerName)->chunkById(100, function ($backups) use ($storage, &$expectedKeys, &$providerAnomalies, $remoteKeys): void {
                    foreach ($backups as $backup) {
                        $visitedDeletions = [];
                        foreach ($backup->objects as $object) {
                            if ($object->status === BackupObject::FAILED) {
                                continue;
                            }
                            $key = $backup->objectKey($object->objectName());
                            $deletion = $backup->objectDeletions->firstWhere('object_name', $object->name);
                            if ($deletion !== null) {
                                $visitedDeletions[] = $deletion->id;
                            }
                            if ($backup->objects_deleted_at !== null || $deletion !== null) {
                                $expectedKeys[$key] = true;
                                if ($deletion === null || $deletion->state === 'legacy_unverified') {
                                    $providerAnomalies[] = ['type' => 'deletion_state_unverified', 'backup_uuid' => $backup->uuid, 'object' => $object->name];
                                } elseif ($deletion->state === 'provider_confirmed' && isset($remoteKeys[$key])) {
                                    $providerAnomalies[] = ['type' => 'object_present_after_delete', 'backup_uuid' => $backup->uuid, 'object' => $object->name];
                                } elseif ($deletion->state !== 'provider_confirmed') {
                                    $providerAnomalies[] = ['type' => 'deletion_reconciliation_pending', 'backup_uuid' => $backup->uuid, 'object' => $object->name, 'state' => $deletion->state];
                                }

                                continue;
                            }
                            $expectedKeys[$key] = true;
                            if ($object->status !== BackupObject::VERIFIED || $object->size === null || $object->sha256 === null) {
                                $providerAnomalies[] = ['type' => 'metadata_not_verified', 'backup_uuid' => $backup->uuid, 'object' => $object->name];

                                continue;
                            }
                            $verification = $storage->verify($key, (int) $object->size, (string) $object->sha256);
                            if (! $verification->ok()) {
                                $providerAnomalies[] = ['type' => 'metadata_object_mismatch', 'backup_uuid' => $backup->uuid, 'object' => $object->name, 'reason' => $verification->failure];
                            }
                        }
                        foreach ($backup->objectDeletions->whereNotIn('id', $visitedDeletions) as $deletion) {
                            $key = $deletion->object_key;
                            $expectedKeys[$key] = true;
                            if ($deletion->state === 'provider_confirmed' && isset($remoteKeys[$key])) {
                                $providerAnomalies[] = ['type' => 'object_present_after_delete', 'backup_uuid' => $backup->uuid, 'object' => $deletion->object_name];
                            } elseif ($deletion->state !== 'provider_confirmed') {
                                $providerAnomalies[] = ['type' => 'deletion_reconciliation_pending', 'backup_uuid' => $backup->uuid, 'object' => $deletion->object_name, 'state' => $deletion->state];
                            }
                        }
                    }
                });
                foreach (array_diff_key($remoteKeys, $expectedKeys) as $key => $_) {
                    $providerAnomalies[] = ['type' => 'orphan_object', 'object_key' => $key];
                }
                if ($storage instanceof IncompleteUploadListable) {
                    foreach ($storage->listIncompleteUploads() as $upload) {
                        try {
                            $key = ObjectKey::assert($upload['object_key']);
                        } catch (\InvalidArgumentException) {
                            $providerAnomalies[] = ['type' => 'incomplete_upload', 'key_shape' => 'unrecognized', 'initiated_at' => $upload['initiated_at']];

                            continue;
                        }
                        $providerAnomalies[] = ['type' => 'incomplete_upload', 'object_key' => $key, 'initiated_at' => $upload['initiated_at']];
                    }
                }
                $report['providers'][$providerName] = ['state' => $providerAnomalies === [] ? 'healthy' : 'reconciliation_required', 'listed_objects' => count($remoteKeys)];
                array_push($report['anomalies'], ...$providerAnomalies);
            } catch (Throwable $error) {
                report($error);
                $report['providers'][$providerName] = ['state' => 'unavailable', 'reason' => 'provider_read_failed'];
                $report['anomalies'][] = ['type' => 'storage_unavailable', 'provider' => $providerName];
                event(new PlatformRecoverySignal('storage_unavailable', ['provider' => $providerName, 'operation' => 'reconciliation']));
            }
        }

        foreach ($report['anomalies'] as $anomaly) {
            event(new PlatformRecoverySignal('storage_reconciliation_anomaly', ['type' => $anomaly['type']]));
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['anomalies'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
