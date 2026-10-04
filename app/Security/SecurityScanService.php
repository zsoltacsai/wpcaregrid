<?php

namespace App\Security;

use App\Connector\Capability;
use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Models\SecurityIncident;
use App\Models\SecurityScan;
use App\Models\Website;
use App\Models\WebsiteJob;
use App\Security\Vulnerabilities\VulnerabilityScanner;
use App\Websites\Actor;
use App\Websites\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Security scans:
 *
 *   request → [vulnerability matching on the server]
 *           → SECURITY_SCAN part 1 → part 2 … (the site stops at its time
 *             budget and continues in the next part) → finish
 *   finish  → resolve what a complete scanner no longer sees → posture
 *           → incident for high-confidence suspicious files
 *
 * The connector only reads; nothing on the site changes during a scan.
 */
class SecurityScanService
{
    public function __construct(
        private readonly JobQueue $jobs,
        private readonly FindingRecorder $findings,
        private readonly VulnerabilityScanner $vulnerabilities,
        private readonly ScanResultIngest $ingest,
        private readonly SecurityPosture $posture,
    ) {}

    /** @throws SecurityRefused */
    public function request(Website $website, ScanType $type, string $actor, ?SecurityIncident $incident = null): SecurityScan
    {
        if ($refusal = $this->refusal($website, $type)) {
            throw new SecurityRefused($refusal);
        }

        $who = Actor::parse($actor);
        $scan = DB::transaction(function () use ($website, $type, $actor, $who, $incident) {
            $scan = $website->securityScans()->create([
                'incident_id' => $incident?->id,
                'type' => $type,
                'status' => ScanStatus::Queued,
                'requested_by' => substr($actor, 0, 64),
                'actor_type' => $who->type,
                'actor_user_id' => $who->storedUserId(),
                'scanner_version' => (string) config('security.scanner_version'),
                'connector_version' => $website->activeConnector?->connector_version,
                'vulnerability_source' => in_array('vulnerability', $type->scanners(), true) ? $this->vulnerabilities->sourceName() : null,
                'coverage' => [],
            ]);

            AuditLog::record($website, 'security.scan_requested', actor: $actor, data: ['scan' => $scan->uuid, 'type' => $type->value]);
            $incident && app(IncidentService::class)->log($incident, 'scan', $actor, ['scan' => $scan->uuid, 'type' => $type->value]);

            return $scan;
        });

        if (in_array('vulnerability', $type->scanners(), true)) {
            $this->runVulnerabilityScan($scan);
        }

        if (! $type->needsConnector()) {
            $this->finish($scan->refresh());

            return $scan->refresh();
        }

        try {
            $this->queuePart($scan, 1);
        } catch (ConnectorError|ValidationException $e) {
            $this->fail($scan, 'JOB_NOT_ALLOWED', $e->getMessage());
        }

        return $scan->refresh();
    }

    /** Why this scan cannot start now, or null. */
    public function refusal(Website $website, ScanType $type): ?string
    {
        $connector = $website->activeConnector;

        return match (true) {
            ! $website->allowsServiceOperations() => 'SUBSCRIPTION_INACTIVE',
            $type->needsConnector() && $connector === null => 'NOT_CONNECTED',
            $type->needsConnector() && ! $connector->can(Capability::SecurityScan) => 'CAPABILITY_MISSING',
            $website->securityScans()->whereIn('status', [ScanStatus::Queued->value, ScanStatus::Running->value])->exists() => 'SCAN_IN_PROGRESS',
            default => null,
        };
    }

    /** The SECURITY_SCAN job reached the site. */
    public function started(SecurityScan $scan, WebsiteJob $job): void
    {
        if ($scan->status === ScanStatus::Queued) {
            $scan->transitionTo(ScanStatus::Running, ['started_at' => now(), 'connector_version' => $job->connector?->connector_version ?? $scan->connector_version]);
            AuditLog::record($scan->website, 'security.scan_started', data: ['scan' => $scan->uuid, 'type' => $scan->type->value]);
        }
    }

    /** A SECURITY_SCAN part ended: record what it found, then continue or finish. */
    public function jobFinished(SecurityScan $scan, WebsiteJob $job): void
    {
        $scan->refresh();
        if ($scan->status->isFinished() || $scan->job_id !== $job->id) {
            return;
        }

        if ($scan->status === ScanStatus::Queued) {
            $scan->transitionTo(ScanStatus::Running, ['started_at' => now()]);
        }

        $reported = $job->result['security'] ?? null;

        if (! in_array($job->status, [JobStatus::Success, JobStatus::Partial], true) || ! is_array($reported)) {
            $code = (string) ($job->error['code'] ?? $job->result_code ?? 'SCAN_FAILED');
            $scan->parts > 1 ? $this->finish($scan, $code) : $this->fail($scan, $code, (string) ($job->error['message'] ?? 'The scan did not complete on the site.'));

            return;
        }

        try {
            $part = $this->ingest->parse($reported);
        } catch (ValidationException $e) {
            Log::warning('Security scan result rejected.', ['scan' => $scan->uuid, 'errors' => array_keys($e->errors())]);
            $this->fail($scan, 'INVALID_RESULT', 'The site reported a result that could not be accepted.');

            return;
        }

        if ($reported['scan_uuid'] !== $scan->uuid) {
            $this->fail($scan, 'INVALID_RESULT', 'The result belongs to another scan.');

            return;
        }

        foreach ($part['findings'] as $finding) {
            $this->findings->record($scan, $finding);
        }

        // The findings now live in security_findings; the job keeps only counts (no second copy).
        $job->forceFill(['result' => ['security' => ['scan_uuid' => $scan->uuid, 'part' => $part['part'], 'complete' => $part['complete'], 'findings' => count($part['findings'])]]])->save();

        $coverage = array_merge($scan->coverage ?? [], $part['coverage']);
        $summary = $scan->summary ?? [];
        if ($part['admins'] !== null) {
            $summary['admins'] = array_map(fn (array $a) => ['id' => (int) $a['id'], 'login' => $a['login'], 'registered' => $a['registered'] ?? null, 'roles' => $a['roles'], 'managed' => (bool) ($a['managed'] ?? false)], $part['admins']);
        }
        $scan->forceFill(['coverage' => $coverage, 'summary' => $summary, 'rules_version' => $part['rules_version'], 'connector_version' => $job->connector?->connector_version ?? $scan->connector_version])->save();

        if ($part['complete']) {
            $this->finish($scan);

            return;
        }

        if ($scan->parts >= (int) config('security.scan.max_parts')) {
            $this->finish($scan, 'SCAN_PART_LIMIT');

            return;
        }

        try {
            $this->queuePart($scan, $scan->parts + 1);
        } catch (ConnectorError|ValidationException $e) {
            $this->finish($scan, 'JOB_NOT_ALLOWED');
        }
    }

    /** Stop a scan that is still waiting or running. Findings already recorded are kept. */
    public function cancel(SecurityScan $scan, string $actor): void
    {
        if ($scan->status->isFinished()) {
            return;
        }

        $scan->job()->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])->update(['status' => JobStatus::Cancelled->value]);
        $scan->transitionTo(ScanStatus::Cancelled, ['completed_at' => now()]);
        AuditLog::record($scan->website, 'security.scan_cancelled', actor: $actor, data: ['scan' => $scan->uuid]);
    }

    /** No progress for too long (security:monitor). */
    public function timeOut(SecurityScan $scan): void
    {
        $scan->job()->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])->update(['status' => JobStatus::Expired->value]);
        $scan->parts > 1 ? $this->finish($scan, 'SCAN_TIMEOUT') : $this->fail($scan, 'SCAN_TIMEOUT', 'The site did not report the scan in time.');
    }

    private function queuePart(SecurityScan $scan, int $part): void
    {
        $job = $this->jobs->queue($scan->website->loadMissing('activeConnector'), JobType::SecurityScan, [
            'scan_uuid' => $scan->uuid,
            'scan_type' => $scan->type->connectorType(),
            'part' => $part,
            'time_budget' => max(10, min(600, (int) config('security.scan.time_budget'))),
        ], 'security');

        $scan->forceFill(['job_id' => $job->id, 'parts' => $part])->save();
    }

    private function runVulnerabilityScan(SecurityScan $scan): void
    {
        $result = $this->vulnerabilities->scan($scan->website);

        foreach ($result['findings'] as $finding) {
            $this->findings->record($scan, $finding);
        }

        $scan->forceFill(['coverage' => array_merge($scan->coverage ?? [], ['vulnerability' => array_filter([
            'status' => $result['status'],
            'reason' => $result['reason'] ?? null,
            'source' => $this->vulnerabilities->sourceName(),
            'checked' => $result['checked'] ?? null,
        ], fn ($v) => $v !== null)])])->save();
    }

    /** Settle the scan: status from coverage, resolution, admin baseline, summary, posture, incident. */
    private function finish(SecurityScan $scan, ?string $stopCode = null): void
    {
        $coverage = $scan->coverage ?? [];
        $scanners = $scan->type->scanners();
        $states = array_map(fn (string $scanner) => $coverage[$scanner]['status'] ?? ($stopCode ? 'partial' : 'failed'), array_combine($scanners, $scanners));
        $completed = array_keys(array_filter($states, fn ($s) => $s === 'completed'));

        $status = match (true) {
            // Nothing ran at all. A scanner that ran partly still counts: its findings are real.
            $completed === [] && ! in_array('partial', $states, true) => ScanStatus::Failed,
            $stopCode !== null || in_array('partial', $states, true) || in_array('failed', $states, true) => ScanStatus::Partial,
            // Unavailable scanners are reported as such; the ones that ran completed.
            default => ScanStatus::Completed,
        };

        if (in_array('malware', $completed, true)) {
            $this->evaluateAdmins($scan);
        }

        // Only a complete run of a scanner may conclude that something is gone.
        $types = array_values(array_filter(FindingType::cases(), fn (FindingType $t) => in_array($t->scanner(), $completed, true)));
        $resolved = $this->findings->resolveMissing($scan, $types);

        $seen = $scan->website->securityFindings()->where('last_scan_id', $scan->id)->active()->get();
        $summary = array_merge($scan->summary ?? [], [
            'findings' => $seen->count(),
            'new' => $seen->where('first_scan_id', $scan->id)->count(),
            'resolved' => $resolved,
            'by_severity' => collect(Severity::cases())->mapWithKeys(fn (Severity $s) => [$s->value => $seen->where('severity', $s)->count()])->all() + ['unrated' => $seen->whereNull('severity')->count()],
            'by_type' => collect(FindingType::cases())->mapWithKeys(fn (FindingType $t) => [$t->value => $seen->where('type', $t)->count()])->filter()->all(),
            'unavailable' => array_keys(array_filter($states, fn ($s) => $s === 'unavailable')),
        ]);

        $scan->transitionTo($status, [
            'summary' => $summary,
            'completed_at' => now(),
            'started_at' => $scan->started_at ?? now(),
            'duration_ms' => (int) round(($scan->started_at ?? $scan->created_at)->diffInMilliseconds(now(), absolute: true)),
            'error_code' => $status === ScanStatus::Completed ? null : ($stopCode ?? ($completed === [] ? 'NO_SCANNER_COMPLETED' : 'SCANNER_INCOMPLETE')),
        ]);

        $event = match ($status) {
            ScanStatus::Completed => 'security.scan_completed',
            ScanStatus::Partial => 'security.scan_partial',
            default => 'security.scan_failed',
        };
        AuditLog::record($scan->website, $event, data: ['scan' => $scan->uuid, 'type' => $scan->type->value, 'findings' => $summary['findings'], 'new' => $summary['new'], 'resolved' => $resolved, 'code' => $scan->error_code]);

        $this->posture->refresh($scan->website, $status !== ScanStatus::Failed ? now() : null);
        app(IncidentService::class)->openForDetection($scan);
        $scan->incident && app(IncidentService::class)->log($scan->incident, 'scan', 'system', ['scan' => $scan->uuid, 'status' => $status->value, 'findings' => $summary['findings']]);
    }

    private function fail(SecurityScan $scan, string $code, string $message = ''): void
    {
        $scan->refresh();
        if ($scan->status->isFinished()) {
            return;
        }

        $scan->job()->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])->update(['status' => JobStatus::Cancelled->value]);
        $scan->transitionTo(ScanStatus::Failed, ['error_code' => substr($code, 0, 64), 'error_message' => substr($message, 0, 500), 'completed_at' => now()]);
        AuditLog::record($scan->website, 'security.scan_failed', data: ['scan' => $scan->uuid, 'type' => $scan->type->value, 'code' => $code]);
        $this->posture->refresh($scan->website);
    }

    /**
     * Administrators compared with the previous complete scan: an admin that
     * was not there before is reported (low confidence: it may be legitimate).
     * The first scan only records the baseline.
     */
    private function evaluateAdmins(SecurityScan $scan): void
    {
        $admins = collect($scan->summary['admins'] ?? null);
        if ($admins->isEmpty()) {
            return;
        }

        $previous = $scan->website->securityScans()->where('id', '<', $scan->id)
            ->whereIn('status', [ScanStatus::Completed->value, ScanStatus::Partial->value])->latest('id')->get()
            ->first(fn (SecurityScan $s) => isset($s->summary['admins']));

        if (! $previous) {
            $scan->forceFill(['summary' => ['baseline' => 'established'] + $scan->summary])->save();

            return;
        }

        // A flagged admin stays flagged until an operator decides; it never becomes
        // "known" just because it was already there at the previous scan.
        $flagged = $scan->website->securityFindings()->where('code', 'NEW_ADMIN_ACCOUNT')->active()->pluck('component')
            ->map(fn (string $component) => (int) substr($component, 5))->all();
        $known = array_diff(collect($previous->summary['admins'])->pluck('id')->all(), $flagged);
        foreach ($admins->reject(fn (array $a) => $a['managed'] || in_array($a['id'], $known, true)) as $admin) {
            $this->findings->record($scan, new ReportedFinding(
                type: FindingType::SuspiciousUser,
                code: 'NEW_ADMIN_ACCOUNT',
                severity: Severity::High,
                confidence: Confidence::Low,
                title: 'New administrator account',
                description: 'An administrator account appeared since the previous scan. It may be legitimate; check who created it.',
                component: 'user:'.$admin['id'],
                evidence: ScanResultIngest::evidence(['user_id' => $admin['id'], 'user_login' => $admin['login'], 'registered' => $admin['registered'], 'roles' => $admin['roles'], 'new_admin' => true]),
            ));
        }
    }
}
