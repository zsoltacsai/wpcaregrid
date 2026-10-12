<?php

namespace App\SiteHealth;

use App\Backups\BackupPolicy;
use App\Backups\BackupStatus;
use App\Connector\HealthStatus;
use App\Connector\Presence;
use App\Models\MonitoringResult;
use App\Models\Website;

/**
 * Unified Site Health: one state per website, from separate dimensions.
 * Each dimension answers for itself; an unknown dimension is shown as
 * unknown and never counted as healthy.
 *
 *   connector   is the site reporting in (Presence)
 *   wordpress   the connector's own health report (health_status)
 *   backups     a recent verified backup for the plan's schedule
 *   updates     pending updates from the inventory
 *   security    SecurityPosture (websites.security_status)
 *
 * Overall: the worst known dimension (critical > attention > healthy);
 * unknown only when nothing is known.
 */
final class SiteHealth
{
    public function __construct(private readonly BackupPolicy $backups) {}

    public function for(Website $website): SiteHealthReport
    {
        $website->loadMissing(['activeConnector', 'inventory']);

        return SiteHealthReport::from([
            $this->connector($website),
            $this->wordpress($website),
            $this->backupsDimension($website),
            $this->updates($website),
            $this->security($website),
            $this->monitoring($website, 'website.http', Dimension::Availability),
            $this->monitoring($website, 'website.tls', Dimension::Tls),
        ]);
    }

    private function monitoring(Website $website, string $checkId, Dimension $dimension): DimensionResult
    {
        $result = MonitoringResult::query()->where('check_id', $checkId)->where('scope', 'website')
            ->where('subject_type', 'website')->where('subject_id', $website->uuid)->first();
        if (! $result || $result->fresh_until?->isPast()) {
            return new DimensionResult($dimension, HealthStatus::Unknown, 'MONITORING_NOT_FRESH');
        }

        return new DimensionResult($dimension, $result->state, $result->reason_code, [
            'observed_at' => $result->observed_at?->toIso8601String(),
            'fresh_until' => $result->fresh_until?->toIso8601String(),
        ]);
    }

    private function connector(Website $website): DimensionResult
    {
        return match ($website->presence()) {
            Presence::Connected => new DimensionResult(Dimension::Connector, HealthStatus::Healthy, 'CONNECTED'),
            Presence::Stale => new DimensionResult(Dimension::Connector, HealthStatus::Attention, 'STALE'),
            Presence::Disconnected => new DimensionResult(Dimension::Connector, HealthStatus::Critical, 'DISCONNECTED'),
            Presence::NeverConnected => new DimensionResult(Dimension::Connector, HealthStatus::Unknown, 'NOT_CONNECTED'),
        };
    }

    private function wordpress(Website $website): DimensionResult
    {
        return new DimensionResult(Dimension::WordPress, $website->health_status ?? HealthStatus::Unknown, $website->health_status ? 'REPORTED' : 'NOT_REPORTED');
    }

    private function backupsDimension(Website $website): DimensionResult
    {
        $policy = $this->backups->for($website);
        if (! $policy->eligible || ! $policy->frequency) {
            return new DimensionResult(Dimension::Backups, HealthStatus::Unknown, $policy->refusal ?? 'NO_SCHEDULE');
        }

        $latest = $website->backups()->where('reason', 'scheduled')->latest('id')->first();
        $verified = $website->backups()->verified()->whereNull('objects_deleted_at')->latest('verified_at')->first();
        $allowed = ['daily' => 2, 'weekly' => 8, 'monthly' => 32][$policy->frequency] ?? 32;

        return match (true) {
            $verified === null => new DimensionResult(Dimension::Backups, HealthStatus::Attention, 'NO_VERIFIED_BACKUP'),
            in_array($latest?->status, [BackupStatus::Failed, BackupStatus::Corrupt], true) => new DimensionResult(Dimension::Backups, HealthStatus::Attention, 'LATEST_FAILED'),
            $verified->verified_at->lt(now()->subDays($allowed)) => new DimensionResult(Dimension::Backups, HealthStatus::Attention, 'BACKUP_OUTDATED'),
            default => new DimensionResult(Dimension::Backups, HealthStatus::Healthy, 'RECENT_VERIFIED_BACKUP'),
        };
    }

    private function updates(Website $website): DimensionResult
    {
        return match (true) {
            $website->inventory === null => new DimensionResult(Dimension::Updates, HealthStatus::Unknown, 'NOT_REPORTED'),
            $website->inventory->updates_pending > 0 => new DimensionResult(Dimension::Updates, HealthStatus::Attention, 'UPDATES_PENDING', ['count' => $website->inventory->updates_pending]),
            default => new DimensionResult(Dimension::Updates, HealthStatus::Healthy, 'UP_TO_DATE'),
        };
    }

    private function security(Website $website): DimensionResult
    {
        return new DimensionResult(Dimension::Security, $website->security_status ?? HealthStatus::Unknown, $website->security_status ? 'ASSESSED' : 'NOT_SCANNED', ['open' => $website->security_open_findings]);
    }
}
