<?php

namespace App\Backups;

use App\Backups\Storage\InspectableBackupStorage;
use App\Backups\Storage\ObjectPresence;
use App\Backups\Storage\StorageManager;
use App\Models\Backup;
use App\Models\BackupObjectDeletion;
use App\Models\BackupRestore;
use App\Models\Website;
use App\Websites\AuditLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Removes logical backup objects with per-object durable intent and read-after-delete.
 * A confirmed absent key is not a claim that provider versions/replicas were physically erased.
 */
class BackupDeletion
{
    public function __construct(private readonly StorageManager $storages) {}

    /** Records cleanup work for a corrupt backup without contacting storage. */
    public function recordUnusableArtifactReconciliation(Backup $backup): void
    {
        DB::transaction(function () use ($backup): void {
            $website = Website::query()->whereKey($backup->website_id)->lockForUpdate()->firstOrFail();
            $locked = Backup::query()->whereKey($backup->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== BackupStatus::Corrupt) {
                throw new BackupRefused('INVALID_STATE', 'Only a corrupt backup can be queued for artifact reconciliation.');
            }
            $provider = (string) $locked->storage_provider;
            if ($provider === '') {
                $locked->forceFill(['deletion_state' => 'reconciliation_required'])->save();
                AuditLog::record($website, 'backup.deletion_reconciliation_required', $locked, data: ['code' => 'provider_unknown']);

                return;
            }
            foreach (BackupObjectName::cases() as $name) {
                BackupObjectDeletion::query()->firstOrCreate(
                    ['backup_id' => $locked->id, 'object_name' => $name->value],
                    ['provider' => $provider, 'object_key' => $locked->objectKey($name), 'state' => 'reconciliation_required', 'verification_state' => 'pending', 'last_error_code' => 'unusable_artifact_cleanup_deferred'],
                );
            }
            $locked->forceFill(['deletion_state' => 'reconciliation_required'])->save();
            AuditLog::record($website, 'backup.deletion_reconciliation_required', $locked, data: ['code' => 'unusable_artifact_cleanup_deferred']);
        }, attempts: 3);
    }

    /** @throws BackupRefused */
    public function remove(Backup $backup, BackupStatus $as, string $actor, bool $confirmOnlyVerified = false): void
    {
        $tasks = DB::transaction(function () use ($backup, $as, $actor): array {
            // Match RestoreService's website → backup lock order. A restore cannot
            // race between its eligibility check and the deletion intent.
            $website = Website::query()->whereKey($backup->website_id)->lockForUpdate()->firstOrFail();
            $locked = Backup::query()->whereKey($backup->id)->where('website_id', $website->id)->lockForUpdate()->firstOrFail();

            $completedObjects = $locked->objectDeletions()->where('state', 'provider_confirmed')->whereNotNull('verified_absent_at')->count();
            if ($locked->status === $as && $locked->deletion_state === 'provider_confirmed' && $completedObjects === count(BackupObjectName::cases())) {
                return [];
            }

            if (! in_array($as, [BackupStatus::Expired, BackupStatus::Deleted], true) || ! $locked->status->canTransitionTo($as)) {
                throw new BackupRefused('INVALID_STATE', "A {$locked->status->value} backup cannot be {$as->value}.");
            }

            $started = ! in_array($locked->deletion_state ?? 'not_requested', ['not_requested', ''], true);
            $otherVerified = $website->backups()->verified()->where('storage_provider', $locked->storage_provider)
                ->whereNull('objects_deleted_at')->whereKeyNot($locked->id)
                ->where(fn ($query) => $query->whereNull('deletion_state')->orWhere('deletion_state', 'not_requested'))
                ->exists();
            // RET-24 is a locked invariant: legacy CLI confirmation cannot
            // authorize removal of the final usable recovery point.
            if ($locked->status === BackupStatus::Verified && ! $otherVerified && ! $started) {
                throw new BackupRefused('ONLY_VERIFIED_BACKUP', 'This is the website destination’s only verified recovery point.');
            }

            $activeRestoreStatuses = array_map(
                fn (RestoreStatus $status) => $status->value,
                array_filter(RestoreStatus::cases(), fn (RestoreStatus $status) => $status->needsRecoveryArtifacts()),
            );
            $inUse = BackupRestore::query()->whereIn('status', $activeRestoreStatuses)
                ->where(fn ($query) => $query->where('backup_id', $locked->id)->orWhere('safety_backup_id', $locked->id))->exists();
            if ($inUse) {
                throw new BackupRefused('BACKUP_IN_USE', 'A restore or compensation depends on this backup.');
            }

            $activeUpdate = $website->jobs()->where('backup_id', $locked->id)->whereIn('status', ['queued', 'waiting', 'delivered'])->exists();
            if ($activeUpdate) {
                throw new BackupRefused('BACKUP_IN_USE', 'An update or compensation workflow depends on this backup.');
            }

            $provider = (string) $locked->storage_provider;
            if ($provider === '') {
                throw new BackupRefused('PROVIDER_UNKNOWN', 'The backup has no recorded storage provider.');
            }

            $locked->forceFill(['deletion_state' => 'delete_requested'])->save();
            foreach (BackupObjectName::cases() as $name) {
                BackupObjectDeletion::query()->firstOrCreate(
                    ['backup_id' => $locked->id, 'object_name' => $name->value],
                    ['provider' => $provider, 'object_key' => $locked->objectKey($name), 'state' => 'pending', 'verification_state' => 'pending'],
                );
            }

            AuditLog::record($website, 'backup.deletion_requested', $locked, actor: $actor, data: ['state' => 'delete_requested']);

            return BackupObjectDeletion::query()->where('backup_id', $locked->id)->orderBy('id')->get()->all();
        }, attempts: 3);

        if ($tasks === []) {
            return;
        }

        try {
            $storage = $this->storages->provider((string) $backup->storage_provider);
        } catch (Throwable) {
            $this->markFailure($backup, 'provider_unavailable');
            throw new BackupRefused('DELETION_RECONCILIATION_REQUIRED', 'Storage could not be reached; deletion remains pending reconciliation.');
        }

        if (! $storage instanceof InspectableBackupStorage) {
            $this->markFailure($backup, 'readback_unsupported');
            throw new BackupRefused('DELETION_RECONCILIATION_REQUIRED', 'Storage has no safe read-after-delete capability.');
        }

        foreach ($tasks as $task) {
            $task = BackupObjectDeletion::query()->whereKey($task->id)->firstOrFail();
            if ($task->state === 'provider_confirmed' && $task->verified_absent_at !== null) {
                continue;
            }

            $task->forceFill([
                'state' => 'delete_requested',
                'requested_at' => $task->requested_at ?? now(),
                'attempts' => $task->attempts + 1,
                'last_error_code' => null,
            ])->save();

            try {
                // Delete is intentionally idempotent. A crash after provider
                // acceptance is recovered by retrying and reading presence.
                $storage->delete($task->object_key);
                $task->forceFill(['provider_response_state' => 'accepted'])->save();
                $presence = $storage->presence($task->object_key);
                if ($presence === ObjectPresence::Absent) {
                    $task->forceFill([
                        'state' => 'provider_confirmed',
                        'verification_state' => 'logical_absence_verified',
                        'verified_absent_at' => now(),
                        'last_error_code' => null,
                    ])->save();
                } else {
                    $task->forceFill([
                        'state' => 'reconciliation_required',
                        'verification_state' => $presence === ObjectPresence::Present ? 'still_present' : 'unknown',
                        'last_error_code' => $presence === ObjectPresence::Present ? 'object_still_present' : 'presence_unknown',
                    ])->save();
                }
            } catch (Throwable) {
                // Never persist provider exception text; it may contain endpoint
                // or credential details. Retry remains possible and idempotent.
                $task->forceFill([
                    'state' => 'reconciliation_required',
                    'provider_response_state' => 'unknown',
                    'verification_state' => 'unknown',
                    'last_error_code' => 'provider_or_readback_error',
                ])->save();
            }
        }

        $fresh = Backup::query()->with('website')->findOrFail($backup->id);
        $allAbsent = BackupObjectDeletion::query()->where('backup_id', $fresh->id)->count() === count(BackupObjectName::cases())
            && ! BackupObjectDeletion::query()->where('backup_id', $fresh->id)
                ->where(fn ($query) => $query->where('state', '<>', 'provider_confirmed')->orWhereNull('verified_absent_at'))->exists();

        if (! $allAbsent) {
            $hasConfirmed = BackupObjectDeletion::query()->where('backup_id', $fresh->id)->where('state', 'provider_confirmed')->exists();
            $fresh->forceFill(['deletion_state' => $hasConfirmed ? 'partially_deleted' : 'reconciliation_required'])->save();
            AuditLog::record($fresh->website, $hasConfirmed ? 'backup.deletion_partial' : 'backup.deletion_reconciliation_required', $fresh, actor: $actor, data: ['state' => $hasConfirmed ? 'partially_deleted' : 'reconciliation_required']);
            throw new BackupRefused('DELETION_RECONCILIATION_REQUIRED', 'Some logical objects remain or could not be verified; backup is not marked deleted.');
        }

        DB::transaction(function () use ($fresh, $as, $actor): void {
            $website = Website::query()->whereKey($fresh->website_id)->lockForUpdate()->firstOrFail();
            $locked = Backup::query()->whereKey($fresh->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === $as && $locked->deletion_state === 'provider_confirmed') {
                return;
            }
            if (! $locked->status->canTransitionTo($as)) {
                throw new BackupRefused('INVALID_STATE', 'The backup state changed during deletion reconciliation.');
            }
            $locked->transitionTo($as, [
                'objects_deleted_at' => now(),
                'deletion_state' => 'provider_confirmed',
            ] + ($as === BackupStatus::Expired ? ['expired_at' => now()] : []));
            AuditLog::record($website, $as === BackupStatus::Expired ? 'backup.expired' : 'backup.deleted', $locked, actor: $actor, data: ['verification' => 'logical_absence_verified', 'provider_versions' => 'unknown']);
        }, attempts: 3);
    }

    private function markFailure(Backup $backup, string $code): void
    {
        $backup->forceFill(['deletion_state' => 'reconciliation_required'])->save();
        BackupObjectDeletion::query()->where('backup_id', $backup->id)->where('state', '<>', 'provider_confirmed')->update([
            'state' => 'reconciliation_required',
            'verification_state' => 'unknown',
            'last_error_code' => $code,
            'updated_at' => now(),
        ]);
        $backup->loadMissing('website');
        AuditLog::record($backup->website, 'backup.deletion_reconciliation_required', $backup, actor: 'system', data: ['code' => $code]);
    }
}
