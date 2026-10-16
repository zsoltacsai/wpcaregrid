<?php

namespace App\Jobs;

use App\Backups\BackupDeletion;
use App\Backups\BackupPolicy;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\Retention;
use App\Backups\Storage\ChecksumMismatch;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StorageNotConfigured;
use App\Backups\Storage\StorageUnavailable;
use App\Models\Backup;
use App\Models\BackupObject;
use App\Websites\AuditLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Moves an uploaded backup from staging to backup storage and verifies
 * every object there (size and SHA-256). Only then is the backup
 * "verified". Storage outages are retried a limited number of times;
 * a checksum mismatch marks the backup corrupt.
 *
 * Runs on its own queue connection ("backups"), whose retry_after is longer
 * than the timeout, so a transfer still running is never picked up by a
 * second worker (which could then mark it failed). At most one transfer
 * per backup is queued at a time; a transfer abandoned by a crashed worker
 * is handed over again by backup:monitor.
 */
class TransferBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    /** @param list<int>|null $expectedObjectIds */
    public function __construct(
        public int $backupId,
        public ?int $expectedWebsiteId = null,
        public ?string $expectedStorageProvider = null,
        public ?array $expectedObjectIds = null,
    ) {
        $this->tries = max(1, (int) config('backup.transfer.tries'));
        $this->timeout = (int) config('backup.transfer.timeout');

        $connection = config('backup.transfer.connection');
        if (config('queue.default') !== 'sync' && is_string($connection) && config("queue.connections.{$connection}")) {
            $this->onConnection($connection);
            $this->onQueue((string) config('backup.transfer.queue'));
        }
    }

    public static function forBackup(Backup $backup): self
    {
        return new self(...self::snapshot($backup));
    }

    /** @return array{int, int, string, list<int>} */
    public static function snapshot(Backup $backup): array
    {
        return [
            (int) $backup->id,
            (int) $backup->website_id,
            (string) $backup->storage_provider,
            $backup->objects()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->backupId;
    }

    /** Longer than any run including retries; released as soon as the job finishes or fails. */
    public function uniqueFor(): int
    {
        return $this->timeout + 900 * max(1, $this->tries);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('backup.transfer.backoff');
    }

    public function handle(StorageManager $storages, BackupService $backups, Retention $retention): void
    {
        // Reject legacy jobs lacking their enqueue-time scope snapshot.
        if ($this->expectedWebsiteId === null || $this->expectedStorageProvider === null || $this->expectedObjectIds === null) {
            return;
        }

        $backup = Backup::whereKey($this->backupId)
            ->where('website_id', $this->expectedWebsiteId)
            ->where('storage_provider', $this->expectedStorageProvider)
            ->first();

        if (! $backup || $backup->status !== BackupStatus::Uploaded) {
            return; // Already handled, or not ready: nothing to do twice.
        }

        $currentObjectIds = $backup->objects()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $expectedObjectIds = $this->expectedObjectIds;
        sort($currentObjectIds);
        sort($expectedObjectIds);
        if ($currentObjectIds !== $expectedObjectIds || $currentObjectIds === []) {
            return; // Dependency set changed after enqueue; leave for reconciliation.
        }

        $backup->transitionTo(BackupStatus::Verifying, ['transfer_attempts' => $backup->transfer_attempts + 1]);

        try {
            $storage = $storages->provider((string) $backup->storage_provider);
            $objects = $backup->objects()->get();

            foreach ($objects as $object) {
                $object->setRelation('backup', $backup);
                if ($object->stored_at === null) {
                    $this->store($storage, $backup, $object);
                }
            }

            foreach ($objects as $object) {
                $result = $storage->verify($backup->objectKey($object->objectName()), (int) $object->size, (string) $object->sha256);

                if (! $result->ok()) {
                    $object->forceFill(['status' => BackupObject::FAILED, 'remote_size' => $result->remoteSize])->save();
                    $this->corrupt($backups, $backup, $result->failure === 'UNVERIFIABLE' ? 'VERIFICATION_UNAVAILABLE' : 'VERIFICATION_'.$result->failure);

                    return;
                }

                $object->forceFill(['status' => BackupObject::VERIFIED, 'remote_size' => $result->remoteSize, 'verification_method' => $result->method, 'verified_at' => now()])->save();
            }
        } catch (ChecksumMismatch) {
            $this->corrupt($backups, $backup, 'CHECKSUM_MISMATCH');

            return;
        } catch (StorageNotConfigured) {
            $backups->fail($backup, 'STORAGE_NOT_CONFIGURED', 'Backup storage is not configured.');

            return;
        } catch (StorageUnavailable $e) {
            Log::warning('Backup storage unavailable.', ['backup' => $backup->uuid, 'error' => $e->getMessage()]);

            if ($this->attempts() < $this->tries) {
                $backup->transitionTo(BackupStatus::Uploaded);
                $this->release($this->backoff()[$this->attempts() - 1] ?? 900);

                return;
            }

            $backups->fail($backup, 'STORAGE_UNAVAILABLE', 'Remote storage was unavailable.');

            return;
        }

        $decision = app(BackupPolicy::class)->for($backup->website);
        $backup->transitionTo(BackupStatus::Verified, [
            'verified_at' => now(),
            'completed_at' => now(),
            'expires_at' => $decision->retentionDays ? now()->addDays($decision->retentionDays) : null,
        ]);
        File::deleteDirectory($this->stagingDirectory($backup));

        AuditLog::record($backup->website, 'backup.verified', $backup, data: ['size' => $backup->total_size, 'storage' => $backup->storage_provider]);
        $backups->settled($backup);
        $retention->apply($backup->website);
    }

    /** Anything unexpected: the backup must not stay in "verifying". */
    public function failed(?\Throwable $e): void
    {
        if ($this->expectedWebsiteId === null || $this->expectedStorageProvider === null || $this->expectedObjectIds === null) {
            return;
        }

        $backup = Backup::whereKey($this->backupId)
            ->where('website_id', $this->expectedWebsiteId)
            ->where('storage_provider', $this->expectedStorageProvider)
            ->first();

        if ($backup && $backup->status->inProgress()) {
            if ($backup->status === BackupStatus::Verifying || $backup->status === BackupStatus::Uploaded) {
                app(BackupService::class)->fail($backup, 'TRANSFER_FAILED', 'Storing the backup failed.');
            }
        }
    }

    private function store($storage, Backup $backup, BackupObject $object): void
    {
        $path = $object->stagingPath();
        $stream = is_file($path) ? fopen($path, 'rb') : false;

        if (! $stream) {
            throw new StorageUnavailable('Staged backup data is missing.');
        }

        try {
            $storage->put($backup->objectKey($object->objectName()), $stream, (int) $object->size, (string) $object->sha256);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $object->forceFill(['status' => BackupObject::STORED, 'stored_at' => now()])->save();
    }

    private function corrupt(BackupService $backups, Backup $backup, string $code): void
    {
        $backup->transitionTo(BackupStatus::Corrupt, ['error_code' => $code, 'error_message' => 'The stored backup did not match what was uploaded.', 'failed_at' => now(), 'completed_at' => now()]);
        AuditLog::record($backup->website, 'backup.corrupt', $backup, data: ['code' => $code]);

        // Cleanup is recorded for explicit reconciliation. A provider call
        // must never be represented as confirmed absence without read-back.
        app(BackupDeletion::class)->recordUnusableArtifactReconciliation($backup);

        File::deleteDirectory($this->stagingDirectory($backup));
        $backups->settled($backup);
    }

    private function stagingDirectory(Backup $backup): string
    {
        return rtrim(config('backup.upload.staging_path'), '/').'/'.$backup->uuid;
    }
}
