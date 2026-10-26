<?php

namespace App\Backups\CustomerDownloads;

use App\Backups\BackupStatus;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StreamableBackupStorage;
use App\Models\Backup;
use App\Models\BackupDownloadGrant;
use App\Models\BackupObject;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Prepare one encrypted block-fingerprint manifest; never copies the backup. */
final class PrepareBackupDownloadGrant implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $grantId)
    {
        $this->onQueue((string) config('backup.transfer.queue', 'backups'));
    }

    public function uniqueId(): string
    {
        return (string) $this->grantId;
    }

    public function handle(BackupDownloadGrants $grants, StorageManager $storages): void
    {
        $grant = BackupDownloadGrant::query()->find($this->grantId);
        if (! $grant || $grant->status !== BackupDownloadGrant::PREPARING || $grant->revoked_at !== null) {
            return;
        }

        if (! $grants->enabled() || $grant->expires_at === null || $grant->expires_at->isPast()) {
            $grants->failPreparation($grant->id, 'PREPARATION_EXPIRED');

            return;
        }

        try {
            $backup = Backup::query()->findOrFail($grant->backup_id);
            $website = Website::query()->findOrFail($grant->website_id);
            $object = BackupObject::query()->findOrFail($grant->backup_object_id);
            $grants->assertEligible($grant->user, $website, $backup, $object, $grant);

            $storage = $storages->provider($grant->storage_provider);
            if (! $storage instanceof StreamableBackupStorage || $storage->name() !== $grant->storage_provider) {
                throw new BackupDownloadRefused('STORAGE_UNSUPPORTED');
            }

            $blockSize = (int) config('backup.downloads.fingerprint_block_size');
            if ($blockSize < 1024 * 1024 || $blockSize > 8 * 1024 * 1024) {
                throw new BackupDownloadRefused('FINGERPRINT_CONFIGURATION_INVALID');
            }

            $hash = hash_init('sha256');
            $total = 0;
            $pending = '';
            $rawBlockHashes = '';
            $consumerBytes = 0;
            $actual = $storage->streamObject($grant->object_key, function (string $chunk) use (&$hash, &$total, &$pending, &$rawBlockHashes, &$consumerBytes, $grant, $blockSize): void {
                if ($chunk === '') {
                    return;
                }
                $length = strlen($chunk);
                $consumerBytes += $length;
                $total += $length;
                if ($total > $grant->object_size) {
                    throw new BackupDownloadRefused('OBJECT_SIZE_MISMATCH');
                }

                hash_update($hash, $chunk);
                $pending .= $chunk;
                while (strlen($pending) >= $blockSize) {
                    $block = substr($pending, 0, $blockSize);
                    $rawBlockHashes .= hash('sha256', $block, true);
                    $pending = substr($pending, $blockSize);
                }
            });

            if ($actual !== $consumerBytes || $total !== $grant->object_size) {
                throw new BackupDownloadRefused('OBJECT_SIZE_MISMATCH');
            }
            if ($pending !== '') {
                $rawBlockHashes .= hash('sha256', $pending, true);
            }
            if (! hash_equals($grant->object_sha256, hash_final($hash))) {
                throw new BackupDownloadRefused('OBJECT_INTEGRITY_MISMATCH');
            }

            $grants->markReady($grant->id, $blockSize, base64_encode($rawBlockHashes));
        } catch (BackupDownloadRefused $e) {
            $grants->failPreparation($grant->id, $e->errorCode);
        } catch (Throwable) {
            // Provider exception messages can include endpoints, keys or paths.
            $grants->failPreparation($grant->id, 'STORAGE_UNAVAILABLE');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(BackupDownloadGrants::class)->failPreparation($this->grantId, 'PREPARATION_FAILED');
    }
}
