<?php

namespace App\Backups;

use App\Backups\Storage\StorageManager;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Websites\AuditLog;

/**
 * Removes a backup's stored objects. The record is kept (status expired or
 * deleted) and the removal is audited. Refuses to remove:
 * - the website's only verified backup, unless an operator explicitly confirms;
 * - a backup an active restore depends on.
 */
class BackupDeletion
{
    public function __construct(private readonly StorageManager $storages) {}

    /** @throws BackupRefused */
    public function remove(Backup $backup, BackupStatus $as, string $actor, bool $confirmOnlyVerified = false): void
    {
        if (! in_array($as, [BackupStatus::Expired, BackupStatus::Deleted], true) || ! $backup->status->canTransitionTo($as)) {
            throw new BackupRefused('INVALID_STATE', "A {$backup->status->value} backup cannot be {$as->value}.");
        }

        $otherVerified = $backup->website->backups()->verified()->whereNull('objects_deleted_at')->whereKeyNot($backup->id)->exists();
        if ($backup->status === BackupStatus::Verified && ! $otherVerified && ! $confirmOnlyVerified) {
            throw new BackupRefused('ONLY_VERIFIED_BACKUP', 'This is the only verified backup of the website.');
        }

        $inUse = BackupRestore::whereIn('status', array_map(fn (RestoreStatus $status) => $status->value, array_filter(RestoreStatus::cases(), fn (RestoreStatus $status) => $status->needsRecoveryArtifacts())))
            ->where(fn ($q) => $q->where('backup_id', $backup->id)->orWhere('safety_backup_id', $backup->id))
            ->exists();
        if ($inUse) {
            throw new BackupRefused('BACKUP_IN_USE', 'A restore depends on this backup.');
        }

        if ($backup->objects_deleted_at === null && $backup->storage_provider) {
            $storage = $this->storages->provider($backup->storage_provider);
            foreach (BackupObjectName::cases() as $name) {
                $storage->delete($backup->objectKey($name));
            }
        }

        $backup->transitionTo($as, ['objects_deleted_at' => $backup->objects_deleted_at ?? now()] + ($as === BackupStatus::Expired ? ['expired_at' => now()] : []));

        AuditLog::record($backup->website, $as === BackupStatus::Expired ? 'backup.expired' : 'backup.deleted', $backup, actor: $actor);
    }
}
