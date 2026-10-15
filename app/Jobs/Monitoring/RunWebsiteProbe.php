<?php

namespace App\Jobs\Monitoring;

use App\Backups\BackupStatus;
use App\Connector\HealthStatus;
use App\Models\Website;
use App\Monitoring\MonitoringEngine;
use App\Monitoring\MonitoringObservation;
use App\Monitoring\MonitoringReason;
use App\Monitoring\MonitoringScope;
use App\Monitoring\MonitoringSeverity;
use App\Monitoring\WebsiteProbe;
use App\Security\SecurityPosture;
use App\SiteHealth\Dimension;
use App\SiteHealth\SiteHealth;
use App\SyntheticFixtures\SyntheticFixturePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class RunWebsiteProbe implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $websiteId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'website-probe:'.$this->websiteId;
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(WebsiteProbe $probe, MonitoringEngine $engine, SyntheticFixturePolicy $synthetic): void
    {
        $website = Website::query()->find($this->websiteId);
        if (! $website) {
            return;
        }
        if (config('app.deployment_environment') === 'staging' && ! $synthetic->ownerPairingAllowed($website)) {
            return;
        }

        $this->recordSourceHealth($website, $engine, app(SiteHealth::class), app(SecurityPosture::class));
        $probe->run($website);
        Cache::put('monitoring.last_probe_completed_at', now()->toIso8601String(), now()->addDay());
    }

    private function recordSourceHealth(Website $website, MonitoringEngine $engine, SiteHealth $siteHealth, SecurityPosture $securityPosture): void
    {
        $presence = $website->presence();
        [$state, $reason, $severity] = match ($presence->value) {
            'connected' => [HealthStatus::Healthy, MonitoringReason::MONITORING_OK, null],
            'stale' => [HealthStatus::Attention, MonitoringReason::CONNECTOR_STALE, MonitoringSeverity::Warning],
            'disconnected' => [HealthStatus::Critical, MonitoringReason::CONNECTOR_DISCONNECTED, MonitoringSeverity::Critical],
            default => [HealthStatus::Unknown, MonitoringReason::CONNECTOR_DELAYED, null],
        };
        $engine->record(new MonitoringObservation(
            'connector.presence', MonitoringScope::Connector, 'website', (string) $website->uuid,
            $state, $severity, $reason, 'connector_report',
            ['presence' => $presence->value], $website->last_seen_at,
            (int) config('connector.stale_after_seconds', 2700),
            $presence->value === 'disconnected' ? 1 : 2, 2,
        ));

        $healthFresh = $website->health_checked_at?->greaterThan(now()->subHour()) ?? false;
        $health = $healthFresh ? ($website->health_status ?? HealthStatus::Unknown) : HealthStatus::Unknown;
        $findings = collect($website->health_findings ?? []);
        $findingStatuses = $findings->pluck('status')->filter()->all();
        $restFailed = $findings->contains(fn (array $finding): bool => ($finding['id'] ?? null) === 'rest_api' && in_array($finding['status'] ?? null, ['warning', 'fail'], true));
        $healthReason = ! $healthFresh ? MonitoringReason::MONITORING_SOURCE_UNVERIFIED
            : ($health === HealthStatus::Healthy ? MonitoringReason::MONITORING_OK
                : ($restFailed ? MonitoringReason::WORDPRESS_REST_FAILED : MonitoringReason::WORDPRESS_HEALTH_DEGRADED));
        $engine->record(new MonitoringObservation(
            'wordpress.health', MonitoringScope::Website, 'website', (string) $website->uuid,
            $health, $health === HealthStatus::Critical ? MonitoringSeverity::Critical : ($health === HealthStatus::Attention ? MonitoringSeverity::Warning : null),
            $healthReason,
            'connector_report', ['health_status' => $health->value, 'finding_count' => count($findingStatuses)], $website->health_checked_at, 3600,
        ));

        $backup = $website->backups()->usableRecoveryPoint()->latest('verified_at')->first();
        $backupDimension = $siteHealth->for($website)->dimension(Dimension::Backups);
        $latestBackup = $website->backups()->latest('id')->first();
        $backupReason = match (true) {
            $latestBackup && in_array($latestBackup->status, [BackupStatus::Failed, BackupStatus::Corrupt], true) => MonitoringReason::BACKUP_FAILED,
            $backupDimension->state === HealthStatus::Unknown => MonitoringReason::MONITORING_SOURCE_UNVERIFIED,
            $backupDimension->state === HealthStatus::Healthy => MonitoringReason::MONITORING_OK,
            default => MonitoringReason::BACKUP_STALE,
        };
        $engine->record(new MonitoringObservation(
            'backup.freshness', MonitoringScope::Website, 'website', (string) $website->uuid,
            $backupDimension->state,
            $backupDimension->state === HealthStatus::Critical ? MonitoringSeverity::Critical : ($backupDimension->state === HealthStatus::Attention ? MonitoringSeverity::Warning : null),
            $backupReason,
            'site_health', ['site_health_reason' => $backupDimension->reason, 'verified_backup_available' => $backup !== null], $backup?->verified_at, 86400,
            1, 1, false,
        ));

        $security = $securityPosture->assess($website);
        $securityReason = match (true) {
            $security['state'] === HealthStatus::Critical => MonitoringReason::SECURITY_CRITICAL_FINDINGS,
            $security['state'] === HealthStatus::Attention => MonitoringReason::SECURITY_FINDINGS_OPEN,
            $security['state'] === HealthStatus::Unknown => MonitoringReason::SECURITY_SCAN_OVERDUE,
            default => MonitoringReason::MONITORING_OK,
        };
        $engine->record(new MonitoringObservation(
            'security.scan', MonitoringScope::Website, 'website', (string) $website->uuid,
            $security['state'], $security['state'] === HealthStatus::Critical ? MonitoringSeverity::Critical : ($security['state'] === HealthStatus::Attention ? MonitoringSeverity::Warning : null),
            $securityReason, 'security_posture', ['open_findings' => $security['open']], $website->security_scanned_at,
            (int) config('security.scan.fresh_for_days', 35) * 86400, 1, 1, false,
        ));
    }
}
