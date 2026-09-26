<?php

namespace App\Security;

use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupType;
use App\Models\SecurityFinding;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentEvent;
use App\Models\SecurityScan;
use App\Models\User;
use App\Models\Website;
use App\Websites\Actor;
use App\Websites\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Security incidents and their timeline.
 *
 *   detection → incident → verified full backup → investigation
 *   → (secure admin access) → containment (quarantine) → remediation
 *   → rescan → health check → functional check → resolved
 *
 * Opening an incident and starting a backup are the only automatic steps;
 * nothing destructive happens without an operator.
 */
class IncidentService
{
    public function __construct(
        private readonly SecurityPosture $posture,
    ) {}

    /** @param  list<SecurityFinding>  $findings */
    public function open(Website $website, string $title, ?Severity $severity, string $actor, array $findings = [], ?string $summary = null): SecurityIncident
    {
        $who = Actor::parse($actor);

        $incident = DB::transaction(function () use ($website, $title, $severity, $actor, $who, $findings, $summary) {
            $incident = $website->securityIncidents()->create([
                'status' => IncidentStatus::Open,
                'severity' => $severity,
                'title' => mb_substr($title, 0, 191),
                'summary' => $summary ? mb_substr($summary, 0, 1000) : null,
                'opened_by' => substr($actor, 0, 64),
                'actor_type' => $who->type,
                'actor_user_id' => $who->userId,
            ]);

            $this->log($incident, 'opened', $actor, ['severity' => $severity?->value]);
            foreach ($findings as $finding) {
                $this->linkFinding($incident, $finding, $actor);
            }

            AuditLog::record($website, 'security.incident_opened', actor: $actor, data: ['incident' => $incident->uuid, 'severity' => $severity?->value]);

            return $incident;
        });

        $this->posture->refresh($website);

        return $incident;
    }

    /**
     * High-confidence, high or critical suspicious files from a scan go into
     * the website's open incident, or a new one (config security.incidents.auto_open),
     * with a verified full backup started for the investigation.
     */
    public function openForDetection(SecurityScan $scan): ?SecurityIncident
    {
        $serious = $scan->website->securityFindings()->where('last_scan_id', $scan->id)->active()
            ->where('type', FindingType::SuspiciousFile)->whereIn('confidence', [Confidence::High->value, Confidence::Confirmed->value])
            ->whereIn('severity', [Severity::High->value, Severity::Critical->value])->get();

        if ($serious->isEmpty()) {
            return null;
        }

        $incident = $scan->website->securityIncidents()->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])->latest('id')->first();

        if ($incident) {
            foreach ($serious as $finding) {
                $this->linkFinding($incident, $finding, 'system');
            }

            return $incident;
        }

        if (! config('security.incidents.auto_open')) {
            return null;
        }

        $severity = $serious->contains(fn (SecurityFinding $f) => $f->severity === Severity::Critical) ? Severity::Critical : Severity::High;
        $incident = $this->open($scan->website, 'Suspicious files detected', $severity, 'system', $serious->all(), 'Opened automatically: the scan found high-confidence suspicious files. Nothing on the site was changed.');

        if (config('security.incidents.auto_backup')) {
            $this->startBackup($incident, 'system');
        }

        return $incident;
    }

    /** A verified full backup for the investigation, through the normal backup service. */
    public function startBackup(SecurityIncident $incident, string $actor): ?string
    {
        try {
            $backup = app(BackupService::class)->request($incident->website, BackupReason::Recovery, $actor, BackupType::Full);
            $this->log($incident, 'backup', $actor, ['backup' => $backup->uuid, 'status' => $backup->status->value]);

            return null;
        } catch (BackupRefused $e) {
            $this->log($incident, 'backup', $actor, ['code' => $e->errorCode, 'status' => 'refused']);

            return $e->errorCode;
        }
    }

    /**
     * @param  bool  $functionalCheck  The operator confirms the site works (checkout, forms, login).
     *
     * @throws SecurityRefused
     */
    public function transition(SecurityIncident $incident, IncidentStatus $next, string $actor, ?string $note = null, bool $functionalCheck = false): void
    {
        if (! $incident->status->canTransitionTo($next)) {
            throw new SecurityRefused('INVALID_TRANSITION');
        }

        if ($next === IncidentStatus::Resolved && ($refusal = $this->resolutionRefusal($incident, $functionalCheck))) {
            throw new SecurityRefused($refusal);
        }

        $from = $incident->status;
        $incident->forceFill(['status' => $next] + match ($next) {
            IncidentStatus::Resolved => ['resolved_at' => now()],
            IncidentStatus::Closed => ['closed_at' => now()],
            // Reopening a resolved incident (resolved → investigating): it is not resolved any more.
            default => $from === IncidentStatus::Resolved ? ['resolved_at' => null] : [],
        })->save();

        $this->log($incident, 'status', $actor, ['from' => $from->value, 'to' => $next->value, 'functional_check' => $next === IncidentStatus::Resolved ? $functionalCheck : null], $note);
        AuditLog::record($incident->website, match ($next) {
            IncidentStatus::Resolved => 'security.incident_resolved',
            IncidentStatus::Closed => 'security.incident_closed',
            default => 'security.incident_status',
        }, actor: $actor, data: ['incident' => $incident->uuid, 'from' => $from->value, 'to' => $next->value]);
        $this->posture->refresh($incident->website);
    }

    /**
     * Resolving needs evidence: a scan after the last change, a health check
     * after it, no high-confidence finding of the incident still open, and
     * the operator's functional check.
     */
    public function resolutionRefusal(SecurityIncident $incident, bool $functionalCheck): ?string
    {
        $since = $incident->remediations()->where('status', RemediationStatus::Completed)->max('completed_at') ?? $incident->created_at;
        $website = $incident->website;

        return match (true) {
            // A scan requested after the incident (or its last change): not the scan that raised it.
            ! $website->securityScans()->whereIn('status', [ScanStatus::Completed->value, ScanStatus::Partial->value])->where('created_at', '>', $since)->exists() => 'RESCAN_REQUIRED',
            $incident->findings()->whereIn('status', [FindingStatus::Open->value, FindingStatus::Confirmed->value])->whereIn('confidence', [Confidence::High->value, Confidence::Confirmed->value])->exists() => 'FINDINGS_STILL_OPEN',
            $website->health_checked_at === null || $website->health_checked_at->lt($since) => 'HEALTH_CHECK_REQUIRED',
            ! $functionalCheck => 'FUNCTIONAL_CHECK_REQUIRED',
            default => null,
        };
    }

    public function note(SecurityIncident $incident, string $actor, string $text): void
    {
        $this->log($incident, 'note', $actor, [], mb_substr(trim($text), 0, 2000));
    }

    public function linkFinding(SecurityIncident $incident, SecurityFinding $finding, string $actor): void
    {
        if ($finding->website_id !== $incident->website_id || $incident->findings()->whereKey($finding->id)->exists()) {
            return;
        }

        $incident->findings()->attach($finding->id);
        $this->log($incident, 'finding', $actor, ['finding' => $finding->uuid, 'code' => $finding->code]);
    }

    /** @param  array<string, scalar|null>  $data */
    public function log(SecurityIncident $incident, string $type, string $actor, array $data = [], ?string $note = null): SecurityIncidentEvent
    {
        $who = Actor::parse($actor);

        return $incident->events()->create([
            'type' => $type,
            'actor' => substr($actor, 0, 64),
            'actor_type' => $who->type,
            'actor_user_id' => $who->userId !== null && User::whereKey($who->userId)->exists() ? $who->userId : null,
            'data' => array_filter($data, fn ($v) => $v !== null) ?: null,
            'note' => $note,
        ]);
    }
}
