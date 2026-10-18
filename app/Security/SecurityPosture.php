<?php

namespace App\Security;

use App\Connector\HealthStatus;
use App\Models\SecurityFinding;
use App\Models\Website;
use Carbon\CarbonInterface;

/**
 * The single place that turns findings, scans and incidents into a
 * website's security state. Site Health and the operator console read the
 * result (websites.security_status); nothing else aggregates findings.
 *
 *   critical   confirmed malware; a critical finding with high confidence;
 *              a critical vulnerability; a critical open incident
 *   attention  any other open finding, or an open incident
 *   healthy    no open findings AND a scanner actually completed recently
 *   unknown    otherwise (never scanned, scanner unavailable, result stale)
 */
final class SecurityPosture
{
    /** @return array{state: HealthStatus, reason: string, open: int} */
    public function assess(Website $website): array
    {
        $active = $website->securityFindings()->active()->get(['id', 'type', 'severity', 'confidence', 'status']);
        $incident = $website->securityIncidents()->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])->orderByDesc('id')->get(['severity']);

        $critical = $active->first(fn (SecurityFinding $f) => $f->status === FindingStatus::Confirmed && $f->type === FindingType::SuspiciousFile
            || $f->severity === Severity::Critical && $f->confidence->atLeast(Confidence::High)
            || $f->severity === Severity::Critical && $f->type === FindingType::Vulnerability);

        return match (true) {
            $critical !== null => ['state' => HealthStatus::Critical, 'reason' => $critical->type === FindingType::Vulnerability ? 'CRITICAL_VULNERABILITY' : 'CRITICAL_FINDING', 'open' => $active->count()],
            $incident->contains(fn ($i) => $i->severity === Severity::Critical) => ['state' => HealthStatus::Critical, 'reason' => 'OPEN_INCIDENT', 'open' => $active->count()],
            $active->isNotEmpty() => ['state' => HealthStatus::Attention, 'reason' => 'OPEN_FINDINGS', 'open' => $active->count()],
            $incident->isNotEmpty() => ['state' => HealthStatus::Attention, 'reason' => 'OPEN_INCIDENT', 'open' => 0],
            $this->recentlyScanned($website) => ['state' => HealthStatus::Healthy, 'reason' => 'NO_OPEN_FINDINGS', 'open' => 0],
            default => ['state' => HealthStatus::Unknown, 'reason' => $website->security_scanned_at ? 'SCAN_STALE' : 'NOT_SCANNED', 'open' => 0],
        };
    }

    /** Recalculate and store the website's security state. */
    public function refresh(Website $website, ?CarbonInterface $scannedAt = null): HealthStatus
    {
        if ($scannedAt) {
            $website->forceFill(['security_scanned_at' => $scannedAt]);
        }

        $posture = $this->assess($website);
        $website->forceFill(['security_status' => $posture['state'], 'security_open_findings' => $posture['open']])->save();

        return $posture['state'];
    }

    /**
     * Every scanner explicitly included in the latest scan completed recently.
     * Partial, unavailable, failed, or missing coverage cannot support a
     * healthy result, whatever an earlier scan reported.
     */
    private function recentlyScanned(Website $website): bool
    {
        $latest = $website->securityScans()
            ->whereIn('status', [ScanStatus::Completed->value, ScanStatus::Partial->value, ScanStatus::Failed->value])
            ->latest('id')->first(['status', 'coverage', 'completed_at']);

        $coverage = collect($latest?->coverage ?? []);

        return $latest !== null
            && $latest->status === ScanStatus::Completed
            && $latest->completed_at?->gte(now()->subDays((int) config('security.scan.fresh_for_days')))
            && $coverage->isNotEmpty()
            && $coverage->every(fn ($scanner) => ($scanner['status'] ?? null) === 'completed');
    }
}
