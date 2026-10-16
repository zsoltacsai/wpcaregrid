<?php

namespace App\Compatibility;

use App\Connector\JobType;
use App\Models\Backup;
use App\Models\Website;

/** Enforces the capability layer independently from authorization and workflow checks. */
final class CapabilityReadiness
{
    public function refusal(Website $website, JobType $type, array $payload = []): ?string
    {
        $required = match ($type) {
            JobType::UpdatePlugin, JobType::UpdateTheme, JobType::UpdateCore => [Capability::UpdateReady],
            JobType::BackupFiles => [[Capability::BackupReady, 'files']],
            JobType::BackupDatabase => [[Capability::BackupReady, 'database']],
            JobType::BackupFull => [[Capability::BackupReady, 'files'], [Capability::BackupReady, 'database']],
            JobType::Restore => [Capability::RestoreReady],
            JobType::SecurityScan => [Capability::SecurityScanReady],
            JobType::SecurityQuarantine, JobType::SecurityRestoreQuarantine, JobType::SecurityRepairFile, JobType::SecurityDisablePlugin => [Capability::SecurityRemediationReady],
            JobType::CreateAdminSession => [Capability::AdminAccessReady],
            default => [],
        };

        if ($required === []) {
            return null;
        }

        $snapshot = $website->capabilitySnapshot;
        $evidenceTtl = $this->requiresRecentPreflight($type)
            ? (int) config('compatibility.operation_ttl_seconds')
            : null;
        if (! $snapshot || ! $snapshot->isFresh($evidenceTtl) || ! $snapshot->matchesCurrent($website, $website->activeConnector)) {
            return 'COMPATIBILITY_UNKNOWN_OR_STALE';
        }

        if ($type === JobType::Restore) {
            $backup = isset($payload['backup_uuid'])
                ? $website->backups()->where('uuid', $payload['backup_uuid'])->first()
                : null;
            if (! $backup) {
                return 'COMPATIBILITY_RESTORE_BACKUP_UNKNOWN';
            }

            $evaluated = app(CapabilityEvaluator::class)->evaluate($website, $website->activeConnector, $snapshot->facts, restoreBackup: $backup, restoreScope: (string) ($payload['scope'] ?? 'full'));

            return $this->statusRefusal($evaluated[Capability::RestoreReady->value]);
        }

        foreach ($required as $item) {
            [$capability, $scope] = is_array($item) ? $item : [$item, null];
            $key = $capability->value;
            $status = $scope === null
                ? $snapshot->status($capability)
                : CapabilityStatus::tryFrom((string) data_get($snapshot->capabilities, $key.'.scopes.'.$scope.'.status'));

            $refusal = $this->statusRefusal([
                'status' => $status?->value ?? CapabilityStatus::Unknown->value,
                'reasons' => data_get($snapshot->capabilities, $key.'.reasons', []),
            ], $key);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    public function restoreRefusal(Website $website, Backup $backup, string $scope): ?string
    {
        return $this->refusal($website, JobType::Restore, ['backup_uuid' => $backup->uuid, 'scope' => $scope]);
    }

    private function statusRefusal(array $result, ?string $key = null): ?string
    {
        $status = CapabilityStatus::tryFrom((string) ($result['status'] ?? '')) ?? CapabilityStatus::Unknown;
        if ($status === CapabilityStatus::Pass) {
            return null;
        }
        if ($status === CapabilityStatus::Warn && ($result['reasons'] ?? []) === ['CONNECTOR_UPDATE_RECOMMENDED']) {
            return null;
        }

        return 'COMPATIBILITY_'.($key ?? Capability::RestoreReady->value).'_'.$status->value;
    }

    private function requiresRecentPreflight(JobType $type): bool
    {
        return in_array($type, [
            JobType::UpdatePlugin,
            JobType::UpdateTheme,
            JobType::UpdateCore,
            JobType::BackupFiles,
            JobType::BackupDatabase,
            JobType::BackupFull,
            JobType::Restore,
            JobType::SecurityQuarantine,
            JobType::SecurityRestoreQuarantine,
            JobType::SecurityRepairFile,
            JobType::SecurityDisablePlugin,
            JobType::CreateAdminSession,
        ], true);
    }
}
