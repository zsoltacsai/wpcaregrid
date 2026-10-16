<?php

namespace App\Security;

use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Compatibility\CapabilityReadiness;
use App\Connector\Capability;
use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Connector\Presence;
use App\Models\Backup;
use App\Models\SecurityFinding;
use App\Models\SecurityIncident;
use App\Models\SecurityQuarantine;
use App\Models\SecurityRemediation;
use App\Models\User;
use App\Models\WebsiteJob;
use App\Operator\Permission;
use App\Websites\Actor;
use App\Websites\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled remediation. Every change:
 *
 *   - is asked for by an operator with security.remediate (never automatic),
 *   - references one finding, one verified FULL backup, the actor and the
 *     before/after state,
 *   - waits for that backup: if it cannot be verified the change is not
 *     made (BACKUP_REQUIRED_FAILED),
 *   - is one fixed connector job that checks the file's current hash first,
 *     so a stale or replayed request never touches a different file.
 *
 * Switched off unless security.remediation.enabled (except undoing a quarantine).
 */
class RemediationService
{
    public const BACKUP_REQUIRED_FAILED = 'BACKUP_REQUIRED_FAILED';

    public function __construct(
        private readonly JobQueue $jobs,
        private readonly SecurityPosture $posture,
    ) {}

    /** @throws SecurityRefused */
    public function request(SecurityFinding $finding, RemediationType $type, User $by, ?SecurityIncident $incident = null): SecurityRemediation
    {
        // An incident given explicitly must be about the same website.
        $refusal = $incident !== null && $incident->website_id !== $finding->website_id ? 'NOT_AUTHORIZED' : $this->refusal($finding, $type, $by);
        if ($refusal) {
            AuditLog::record($finding->website, 'security.remediation_refused', actor: Actor::user($by)->label(), data: ['finding' => $finding->uuid, 'type' => $type->value, 'code' => $refusal]);

            throw new SecurityRefused($refusal);
        }

        $incident ??= $finding->incidents()->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])->latest('id')->first();
        $actor = Actor::user($by);

        $remediation = DB::transaction(function () use ($finding, $type, $incident, $actor) {
            $remediation = $finding->website->securityRemediations()->create([
                'finding_id' => $finding->id,
                'incident_id' => $incident?->id,
                'type' => $type,
                'status' => RemediationStatus::AwaitingBackup,
                'requested_by' => $actor->label(),
                'actor_type' => $actor->type,
                'actor_user_id' => $actor->userId,
                'before' => array_filter([
                    'path' => $finding->path,
                    'sha256' => $finding->sha256(),
                    'size' => $finding->evidence['size'] ?? null,
                    'version' => $finding->evidence['version'] ?? null,
                    'finding_status' => $finding->status->value,
                ], fn ($v) => $v !== null),
            ]);

            AuditLog::record($finding->website, 'security.remediation_requested', actor: $actor->label(), data: ['remediation' => $remediation->uuid, 'finding' => $finding->uuid, 'incident' => $incident?->uuid, 'type' => $type->value]);
            $incident && app(IncidentService::class)->log($incident, 'remediation', $actor->label(), ['remediation' => $remediation->uuid, 'type' => $type->value, 'status' => 'requested']);

            return $remediation;
        });

        if (! $type->needsBackup()) {
            $this->dispatch($remediation);

            return $remediation->refresh();
        }

        // A recent verified full backup counts; otherwise make one and wait for it.
        $recent = $finding->website->backups()->usableRecoveryPoint()->where('type', BackupType::Full)
            ->where('verified_at', '>=', now()->subMinutes((int) config('security.remediation.backup_max_age_minutes')))->latest('verified_at')->first();

        if ($recent) {
            $remediation->forceFill(['backup_id' => $recent->id])->save();
            $this->dispatch($remediation);

            return $remediation->refresh();
        }

        try {
            $backup = app(BackupService::class)->request($finding->website, BackupReason::Recovery, $actor->label(), BackupType::Full);
            $remediation->forceFill(['backup_id' => $backup->id])->save();
        } catch (BackupRefused $e) {
            $this->block($remediation, $e->errorCode);
        }

        return $remediation->refresh();
    }

    /** Why this remediation may not be requested, or null. */
    public function refusal(SecurityFinding $finding, RemediationType $type, User $by): ?string
    {
        $website = $finding->website;
        $connector = $website->activeConnector;
        $capability = $type === RemediationType::QuarantineFile || $type === RemediationType::RestoreQuarantinedFile ? Capability::SecurityQuarantine : Capability::SecurityRemediate;

        if ($readiness = app(CapabilityReadiness::class)->refusal($website, $type->jobType())) {
            return $readiness;
        }

        return match (true) {
            ! $by->hasOperatorPermission(Permission::SecurityRemediate) => 'NOT_AUTHORIZED',
            $type !== RemediationType::RestoreQuarantinedFile && ! config('security.remediation.enabled') => 'REMEDIATION_DISABLED',
            $type === RemediationType::DisablePlugin && ! config('security.remediation.allow_disable_plugin') => 'DISABLE_PLUGIN_NOT_ALLOWED',
            ($applies = $this->applies($finding, $type)) !== null => $applies,
            $website->securityRemediations()->where('finding_id', $finding->id)->whereIn('status', ['awaiting_backup', 'queued', 'running'])->exists() => 'REMEDIATION_IN_PROGRESS',
            ! $website->allowsServiceOperations() => 'SUBSCRIPTION_INACTIVE',
            $connector === null => 'NOT_CONNECTED',
            $connector->presence() !== Presence::Connected => 'CONNECTOR_OFFLINE',
            ! $connector->can($capability) => 'CAPABILITY_MISSING',
            default => null,
        };
    }

    /** Whether this kind of remediation fits this finding. */
    private function applies(SecurityFinding $finding, RemediationType $type): ?string
    {
        return match ($type) {
            // Removing a file needs an operator's confirmation first, never a scanner's guess.
            RemediationType::QuarantineFile => match (true) {
                ! $finding->type->concernsFile() || $finding->path === null || $finding->sha256() === null => 'NOT_A_FILE_FINDING',
                $finding->status !== FindingStatus::Confirmed => 'FINDING_NOT_CONFIRMED',
                default => null,
            },
            RemediationType::RestoreQuarantinedFile => $finding->status === FindingStatus::Quarantined && $this->activeQuarantine($finding) ? null : 'NOT_QUARANTINED',
            RemediationType::RepairCoreFile => $finding->type === FindingType::Integrity && $finding->component === 'core'
                && in_array($finding->code, ['CORE_FILE_MODIFIED', 'CORE_FILE_MISSING'], true) && $finding->status->isActive() ? null : 'NOT_REPAIRABLE',
            RemediationType::RepairPluginFile => $finding->type === FindingType::Integrity && str_starts_with((string) $finding->component, 'plugin:')
                && in_array($finding->code, ['PLUGIN_FILE_MODIFIED', 'PLUGIN_FILE_MISSING'], true) && $finding->status->isActive() && $this->pluginBasename($finding) ? null : 'NOT_REPAIRABLE',
            RemediationType::DisablePlugin => $finding->status->isActive() && $this->pluginBasename($finding) ? null : 'NOT_A_PLUGIN_FINDING',
        };
    }

    /** Undo a quarantine: the file goes back exactly where it was (never over another file). */
    public function restoreQuarantine(SecurityQuarantine $quarantine, User $by): SecurityRemediation
    {
        return $this->request($quarantine->finding, RemediationType::RestoreQuarantinedFile, $by);
    }

    /** A backup this remediation waited for settled. */
    public function backupSettled(Backup $backup): void
    {
        SecurityRemediation::where('backup_id', $backup->id)->where('status', RemediationStatus::AwaitingBackup)->get()
            ->each(fn (SecurityRemediation $remediation) => $backup->status === BackupStatus::Verified
                ? $this->dispatch($remediation)
                : $this->block($remediation, $backup->error_code ?? $backup->status->value));
    }

    public function started(SecurityRemediation $remediation): void
    {
        if ($remediation->status === RemediationStatus::Queued) {
            $remediation->transitionTo(RemediationStatus::Running);
        }
    }

    /** The connector reported the result of the change. */
    public function jobFinished(SecurityRemediation $remediation, WebsiteJob $job): void
    {
        $remediation->refresh();
        if (! $remediation->status->isActive()) {
            return;
        }

        $reported = is_array($job->result['remediation'] ?? null) ? $job->result['remediation'] : [];
        $after = array_intersect_key($reported['after'] ?? [], array_flip(['path', 'sha256', 'size', 'exists', 'active', 'version']));

        if ($job->status !== JobStatus::Success || ($reported['remediation_uuid'] ?? null) !== $remediation->uuid) {
            $code = (string) ($job->error['code'] ?? $job->result_code ?? 'REMEDIATION_FAILED');
            $remediation->transitionTo(RemediationStatus::Failed, ['error_code' => substr($code, 0, 64), 'error_message' => substr((string) ($job->error['message'] ?? 'The change was not made.'), 0, 500), 'after' => $after ?: null, 'completed_at' => now()]);
            $this->audit($remediation, 'security.remediation_failed', ['code' => $code]);

            return;
        }

        DB::transaction(function () use ($remediation, $reported, $after) {
            $finding = $remediation->finding;
            $quarantine = $this->recordQuarantine($remediation, $reported['quarantine'] ?? null);

            [$findingStatus, $note] = match ($remediation->type) {
                RemediationType::QuarantineFile => [FindingStatus::Quarantined, 'Quarantined by remediation.'],
                RemediationType::RestoreQuarantinedFile => [FindingStatus::Open, 'Quarantine undone: the file is back.'],
                default => [FindingStatus::Remediated, 'Remediated: '.$remediation->type->value.'.'],
            };

            if ($remediation->type === RemediationType::RestoreQuarantinedFile && ($restored = $this->activeQuarantine($finding))) {
                $restored->forceFill(['status' => SecurityQuarantine::RESTORED, 'restored_at' => now(), 'restored_by_user_id' => $remediation->actor_user_id])->save();
            }

            $remediation->transitionTo(RemediationStatus::Completed, ['after' => $after ?: null, 'quarantine_id' => $quarantine?->id ?? $remediation->quarantine_id, 'completed_at' => now()]);
            $finding->forceFill(['status' => $findingStatus, 'resolved_at' => $findingStatus === FindingStatus::Open ? null : now(), 'status_changed_at' => now(), 'status_changed_by_user_id' => $remediation->actor_user_id, 'status_note' => $note])->save();
        });

        $this->audit($remediation, match ($remediation->type) {
            RemediationType::QuarantineFile => 'security.finding_quarantined',
            RemediationType::RestoreQuarantinedFile => 'security.quarantine_restored',
            default => 'security.finding_remediated',
        }, ['result' => $job->result_code]);
        $this->posture->refresh($remediation->website);
    }

    private function dispatch(SecurityRemediation $remediation): void
    {
        $finding = $remediation->finding;
        $quarantine = $remediation->type === RemediationType::RestoreQuarantinedFile ? $this->activeQuarantine($finding) : null;

        $payload = match ($remediation->type) {
            RemediationType::QuarantineFile => ['remediation_uuid' => $remediation->uuid, 'finding_uuid' => $finding->uuid, 'path' => $finding->path, 'sha256' => $finding->sha256()],
            RemediationType::RestoreQuarantinedFile => ['remediation_uuid' => $remediation->uuid, 'quarantine_uuid' => $quarantine->uuid, 'path' => $quarantine->original_path, 'sha256' => $quarantine->sha256],
            RemediationType::RepairCoreFile => array_filter(['remediation_uuid' => $remediation->uuid, 'source' => 'core', 'path' => $finding->path, 'sha256' => $finding->sha256()]),
            RemediationType::RepairPluginFile => array_filter(['remediation_uuid' => $remediation->uuid, 'source' => 'plugin', 'plugin' => $this->pluginBasename($finding), 'path' => $finding->path, 'sha256' => $finding->sha256()]),
            RemediationType::DisablePlugin => ['remediation_uuid' => $remediation->uuid, 'plugin' => $this->pluginBasename($finding)],
        };

        try {
            $job = $this->jobs->queue($remediation->website->loadMissing('activeConnector'), $remediation->type->jobType(), $payload, substr($remediation->requested_by, 0, 32));
        } catch (ConnectorError|ValidationException $e) {
            $remediation->transitionTo(RemediationStatus::Failed, ['error_code' => 'JOB_NOT_ALLOWED', 'error_message' => substr($e->getMessage(), 0, 500), 'completed_at' => now()]);
            $this->audit($remediation, 'security.remediation_failed', ['code' => 'JOB_NOT_ALLOWED']);

            return;
        }

        $remediation->transitionTo(RemediationStatus::Queued, ['job_id' => $job->id]);
    }

    private function block(SecurityRemediation $remediation, string $reason): void
    {
        $remediation->transitionTo(RemediationStatus::Blocked, [
            'error_code' => self::BACKUP_REQUIRED_FAILED,
            'error_message' => substr("The change was not made: the required backup could not be verified ({$reason}).", 0, 500),
            'completed_at' => now(),
        ]);
        $this->audit($remediation, 'security.remediation_blocked', ['code' => self::BACKUP_REQUIRED_FAILED, 'backup' => $reason]);
    }

    private function recordQuarantine(SecurityRemediation $remediation, mixed $reported): ?SecurityQuarantine
    {
        if (! is_array($reported) || ! is_string($reported['original_path'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', (string) ($reported['sha256'] ?? ''))
            || ! preg_match(JobType::SITE_PATH, $reported['original_path']) || preg_match(JobType::SITE_PATH_UNSAFE, $reported['original_path'])) {
            return null;
        }

        return SecurityQuarantine::create([
            'website_id' => $remediation->website_id,
            'finding_id' => $remediation->finding_id,
            'remediation_id' => $remediation->id,
            'original_path' => $reported['original_path'],
            'sha256' => $reported['sha256'],
            'size' => is_int($reported['size'] ?? null) ? $reported['size'] : null,
            'original_modified_at' => is_string($reported['modified_at'] ?? null) ? Carbon::parse($reported['modified_at']) : null,
            'original_permissions' => is_string($reported['permissions'] ?? null) && preg_match('/^0?[0-7]{3,4}$/', $reported['permissions']) ? $reported['permissions'] : null,
            'reason' => mb_substr($remediation->finding->code.': '.$remediation->finding->title, 0, 191),
            'status' => SecurityQuarantine::QUARANTINED,
            'quarantined_at' => now(),
        ]);
    }

    private function activeQuarantine(SecurityFinding $finding): ?SecurityQuarantine
    {
        return SecurityQuarantine::where('finding_id', $finding->id)->where('status', SecurityQuarantine::QUARANTINED)->latest('id')->first();
    }

    /** The plugin a finding belongs to, as WordPress knows it ("akismet/akismet.php"), from the inventory. */
    private function pluginBasename(SecurityFinding $finding): ?string
    {
        $slug = str_starts_with((string) $finding->component, 'plugin:') ? substr($finding->component, 7) : null;
        if ($slug === null && preg_match('#^wp-content/plugins/([^/]+)/#', (string) $finding->path, $m)) {
            $slug = $m[1];
        }

        $file = $slug ? collect($finding->website->inventory?->plugins)->first(fn (array $p) => explode('/', $p['file'])[0] === $slug)['file'] ?? null : null;

        return is_string($file) && str_contains($file, '/') ? $file : null;
    }

    private function audit(SecurityRemediation $remediation, string $event, array $data): void
    {
        // Outcomes are reported by the site; the request itself was audited with the operator.
        // Each entry links the remediation, its finding, the backup it depended on and the incident.
        AuditLog::record($remediation->website, $event, $remediation->backup, data: [
            'remediation' => $remediation->uuid,
            'type' => $remediation->type->value,
            'finding' => $remediation->finding?->uuid,
            'incident' => $remediation->incident?->uuid,
            'backup_uuid' => $remediation->backup?->uuid,
            'requested_by' => $remediation->requested_by,
        ] + $data);
        $remediation->incident && app(IncidentService::class)->log($remediation->incident, 'remediation', 'system', ['remediation' => $remediation->uuid, 'type' => $remediation->type->value, 'status' => $remediation->refresh()->status->value] + $data);
    }
}
