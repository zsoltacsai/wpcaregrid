<?php

namespace App\Console\Commands\Backups;

use App\Backups\BackupDeletion;
use App\Backups\BackupObjectName;
use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Backups\Storage\StorageManager;
use App\Models\Backup;
use App\Models\Website;
use App\Websites\AuditLog;
use Illuminate\Console\Command;

/**
 * Operator actions on backups, always by UUID and always through the
 * backup domain: there is no way to delete arbitrary storage objects.
 *
 *   backup:admin list {website}      backups of a website
 *   backup:admin show {backup}       details and audit log
 *   backup:admin run {website}       request a manual backup
 *   backup:admin retry {backup}      new backup replacing a failed one
 *   backup:admin verify {backup}     re-check stored objects
 *   backup:admin delete {backup}     remove stored objects, keep the record
 */
class BackupAdmin extends Command
{
    protected $signature = 'backup:admin {action : list|show|run|retry|verify|delete} {uuid : Website or backup UUID}
        {--type= : files|database|full (run)} {--confirm-only-backup : Allow deleting the only verified backup}';

    protected $description = 'Operator actions on backups';

    public function handle(BackupService $backups, BackupDeletion $deletion, StorageManager $storages): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list($this->website()),
                'show' => $this->show($this->backup()),
                'run' => $this->requestBackup($backups, $this->website()),
                'retry' => $this->retry($backups, $this->backup()),
                'verify' => $this->verify($storages, $backups, $this->backup()),
                'delete' => $this->delete($deletion, $this->backup()),
                default => $this->fail('Unknown action.'),
            };
        } catch (BackupRefused $e) {
            $this->components->error("Refused: {$e->errorCode}".($e->getMessage() !== $e->errorCode ? " – {$e->getMessage()}" : ''));

            return self::FAILURE;
        }
    }

    private function list(Website $website): int
    {
        $this->table(['UUID', 'Created', 'Type', 'Reason', 'Status', 'Size', 'Expires'], $website->backups()->latest('id')->limit(50)->get()->map(fn (Backup $b) => [
            $b->uuid, $b->created_at, $b->type->value, $b->reason->value, $b->status->value.($b->error_code ? " ({$b->error_code})" : ''), $b->total_size, $b->expires_at ?? '—',
        ]));

        return self::SUCCESS;
    }

    private function show(Backup $backup): int
    {
        $this->components->twoColumnDetail('Website', $backup->website->uuid);
        foreach (['type', 'reason', 'status', 'error_code', 'storage_provider', 'storage_path', 'total_size', 'created_at', 'verified_at', 'expires_at', 'objects_deleted_at'] as $field) {
            $value = $backup->{$field};
            $this->components->twoColumnDetail($field, (string) ($value instanceof \BackedEnum ? $value->value : $value));
        }
        foreach ($backup->objects as $object) {
            $this->components->twoColumnDetail("object {$object->name}", "{$object->status} {$object->size} B sha256:{$object->sha256} ({$object->verification_method})");
        }
        $this->newLine();
        $this->table(['Time', 'Event', 'Actor', 'Data'], $backup->website->events()->where('backup_id', $backup->id)->oldest('id')->get()
            ->map(fn ($e) => [$e->created_at, $e->event, $e->actor, json_encode($e->data)]));

        return self::SUCCESS;
    }

    private function requestBackup(BackupService $backups, Website $website): int
    {
        $type = $this->option('type') ? BackupType::from($this->option('type')) : null;
        // Operator backups are "recovery": the customer cooldown does not apply.
        $backup = $backups->request($website, BackupReason::Recovery, 'operator', $type);
        $this->components->info("Queued backup {$backup->uuid}");

        return self::SUCCESS;
    }

    private function retry(BackupService $backups, Backup $failed): int
    {
        if (! in_array($failed->status, [BackupStatus::Failed, BackupStatus::Corrupt], true)) {
            throw new BackupRefused('INVALID_STATE', 'Only failed or corrupt backups can be retried.');
        }

        $reason = $failed->reason === BackupReason::Scheduled ? BackupReason::Recovery : $failed->reason;
        $backup = $backups->request($failed->website, $reason === BackupReason::PreUpdate || $reason === BackupReason::PreRestore ? BackupReason::Recovery : $reason, 'operator', $failed->type, ['retry_of_id' => $failed->id]);
        $this->components->info("Queued backup {$backup->uuid} (retry of {$failed->uuid})");

        return self::SUCCESS;
    }

    private function verify(StorageManager $storages, BackupService $backups, Backup $backup): int
    {
        if ($backup->status !== BackupStatus::Verified) {
            throw new BackupRefused('INVALID_STATE', 'Only verified backups can be re-verified.');
        }

        $backup->transitionTo(BackupStatus::Verifying);
        $storage = $storages->provider((string) $backup->storage_provider);

        foreach ($backup->objects as $object) {
            $result = $storage->verify($backup->objectKey(BackupObjectName::from($object->name)), (int) $object->size, (string) $object->sha256);
            if (! $result->ok()) {
                $backup->transitionTo(BackupStatus::Corrupt, ['error_code' => 'VERIFICATION_'.$result->failure, 'failed_at' => now()]);
                AuditLog::record($backup->website, 'backup.corrupt', $backup, actor: 'operator', data: ['code' => 'VERIFICATION_'.$result->failure]);
                $this->components->error("{$object->name}: {$result->failure}");

                return self::FAILURE;
            }
        }

        $backup->transitionTo(BackupStatus::Verified, ['verified_at' => now()]);
        AuditLog::record($backup->website, 'backup.verified', $backup, actor: 'operator', data: ['reverified' => true]);
        $this->components->info('All objects verified.');

        return self::SUCCESS;
    }

    private function delete(BackupDeletion $deletion, Backup $backup): int
    {
        $deletion->remove($backup, BackupStatus::Deleted, 'operator', (bool) $this->option('confirm-only-backup'));
        $this->components->info("Deleted the stored objects of {$backup->uuid}; the record is kept.");

        return self::SUCCESS;
    }

    private function website(): Website
    {
        return Website::where('uuid', $this->argument('uuid'))->firstOr(fn () => throw new BackupRefused('NOT_FOUND', 'Unknown website.'));
    }

    private function backup(): Backup
    {
        return Backup::where('uuid', $this->argument('uuid'))->firstOr(fn () => throw new BackupRefused('NOT_FOUND', 'Unknown backup.'));
    }
}
