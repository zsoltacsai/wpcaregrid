<?php

namespace App\Console\Commands\Monitoring;

use App\Connector\HealthStatus;
use App\Jobs\Monitoring\RecordWorkerPulse;
use App\Jobs\Monitoring\RunWebsiteProbe;
use App\Models\AdminAuditEvent;
use App\Models\MonitoringAlertDelivery;
use App\Models\MonitoringResult;
use App\Models\PlatformBackupDelivery;
use App\Models\PlatformDatabaseBackup;
use App\Models\SecurityScan;
use App\Models\Website;
use App\Monitoring\MonitoringEngine;
use App\Monitoring\MonitoringObservation;
use App\Monitoring\MonitoringReason;
use App\Monitoring\MonitoringScope;
use App\Monitoring\MonitoringSeverity;
use App\Owner\OwnerSettings;
use App\Owner\SystemStatus;
use App\Platform\DisasterRecovery\PlatformRecoveryHealth;
use App\Security\Vulnerabilities\VulnerabilitySource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RunMonitoring extends Command
{
    protected $signature = 'monitoring:run';

    protected $description = 'Queue bounded website checks and sample authoritative platform health sources';

    public function handle(MonitoringEngine $engine): int
    {
        $this->database($engine);
        $this->scheduler($engine);
        $this->queue($engine);
        $this->workers($engine);
        $this->platformBackup($engine);
        $this->platformRecovery($engine);
        $this->storageProvider($engine);
        $this->mailProvider($engine);
        $this->stripeProvider($engine);
        $this->security($engine);
        $this->pipeline($engine);

        $batchSize = max(1, min(2000, (int) config('monitoring.website_batch_size', 250)));
        $queued = 0;
        $sites = Website::query();
        if (config('app.deployment_environment') === 'staging') {
            $fixtureUuid = (string) config('synthetic_fixtures.site_uuid');
            if ($fixtureUuid === '') {
                $sites->whereRaw('1 = 0');
            } else {
                $sites->where('uuid', $fixtureUuid)->whereHas('syntheticFixture', fn ($query) => $query
                    ->where('purpose', config('synthetic_fixtures.purpose'))->where('environment', 'staging')->where('enabled', true)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())));
            }
        }
        $sites
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('monitoring_results')
                    ->whereColumn('monitoring_results.subject_id', 'websites.uuid')
                    ->where('monitoring_results.check_id', 'website.http')
                    ->where('monitoring_results.next_check_at', '>', now());
            })
            ->orderByRaw("(select coalesce(max(monitoring_results.observed_at), '1970-01-01 00:00:00') from monitoring_results where monitoring_results.subject_id = websites.uuid and monitoring_results.check_id = 'website.http') asc")
            ->orderBy('id')
            ->limit($batchSize)
            ->get(['id'])
            ->each(function (Website $website) use (&$queued): void {
                RunWebsiteProbe::dispatch($website->id);
                $queued++;
            });

        $engine->record(new MonitoringObservation(
            'monitoring.dispatch', MonitoringScope::Platform, 'platform', 'self', HealthStatus::Healthy,
            null, MonitoringReason::MONITORING_OK, 'scheduler', ['probes_queued' => $queued], now(), 900,
        ));
        Cache::put('monitoring.pipeline_last_dispatch', now()->toIso8601String(), now()->addDay());
        $this->line('queued='.$queued);

        return self::SUCCESS;
    }

    private function database(MonitoringEngine $engine): void
    {
        $started = microtime(true);
        try {
            DB::select('select 1');
            $latency = (int) round((microtime(true) - $started) * 1000);
            $engine->record(new MonitoringObservation(
                'platform.database', MonitoringScope::Platform, 'platform', 'primary', HealthStatus::Healthy,
                null, MonitoringReason::MONITORING_OK, 'queue', ['latency_ms' => $latency], now(), 180,
            ));
        } catch (\Throwable) {
            $engine->record(new MonitoringObservation(
                'platform.database', MonitoringScope::Platform, 'platform', 'primary', HealthStatus::Critical,
                MonitoringSeverity::Critical, MonitoringReason::DATABASE_UNAVAILABLE, 'queue', [], now(), 180, 1, 2,
            ));
        }
    }

    private function scheduler(MonitoringEngine $engine): void
    {
        $last = Cache::get(SystemStatus::SCHEDULER_HEARTBEAT);
        $at = is_string($last) ? Carbon::parse($last) : null;
        $stale = ! $at || $at->lt(now()->subMinutes(5));
        $engine->record(new MonitoringObservation(
            'platform.scheduler', MonitoringScope::Platform, 'platform', 'scheduler',
            $stale ? HealthStatus::Critical : HealthStatus::Healthy,
            $stale ? MonitoringSeverity::Critical : null,
            $stale ? MonitoringReason::SCHEDULER_STALE : MonitoringReason::MONITORING_OK,
            'scheduler', ['last_heartbeat_at' => $at?->toIso8601String()], $at, 180, 2, 2,
        ));
    }

    private function queue(MonitoringEngine $engine): void
    {
        $connection = (string) config('queue.default');
        $driver = (string) config('queue.connections.'.$connection.'.driver');
        if ($driver !== 'database' || ! Schema::hasTable('jobs')) {
            $engine->record(new MonitoringObservation(
                'platform.queue', MonitoringScope::Platform, 'platform', 'default', HealthStatus::Unknown,
                null, MonitoringReason::QUEUE_BACKLOG, 'queue', ['queue_driver' => $driver, 'queue_depth_supported' => false], now(), 600, 1, 1, false,
            ));

            return;
        }

        $oldest = DB::table('jobs')->min('created_at');
        $depth = DB::table('jobs')->count();
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        $oldestAge = $oldest ? max(0, now()->timestamp - (int) $oldest) : 0;
        $problem = $oldestAge > 900 || $failed > 0;
        $engine->record(new MonitoringObservation(
            'platform.queue', MonitoringScope::Platform, 'platform', 'default',
            $problem ? HealthStatus::Attention : HealthStatus::Healthy,
            $problem ? MonitoringSeverity::Warning : null,
            $problem ? MonitoringReason::QUEUE_BACKLOG : MonitoringReason::MONITORING_OK,
            'queue', ['depth' => $depth, 'oldest_age_seconds' => $oldestAge, 'failed_jobs' => $failed], now(), 300, 2, 2,
        ));
    }

    private function platformBackup(MonitoringEngine $engine): void
    {
        $latest = PlatformDatabaseBackup::query()->where('status', 'verified')->latest('verified_at')->first();
        $healthy = $latest?->verified_at?->greaterThan(now()->subHours(24)) ?? false;
        $engine->record(new MonitoringObservation(
            'platform.backup', MonitoringScope::Platform, 'platform', 'database',
            $healthy ? HealthStatus::Healthy : HealthStatus::Attention,
            $healthy ? null : MonitoringSeverity::Warning,
            $healthy ? MonitoringReason::MONITORING_OK : MonitoringReason::PLATFORM_BACKUP_STALE,
            'domain_service', ['verified_backup_available' => (bool) $latest], $latest?->verified_at, 1800,
            1, 1,
        ));
    }

    private function platformRecovery(MonitoringEngine $engine): void
    {
        $latest = PlatformDatabaseBackup::query()->latest('created_for_backup_at')->first();
        $health = $latest?->recoveryHealth() ?? PlatformRecoveryHealth::NotConfigured;
        $deliveries = $latest
            ? PlatformBackupDelivery::query()->where('platform_database_backup_id', $latest->id)->get(['destination_key', 'verification_status', 'reconciliation_status'])
            : collect();
        $primaryDelivery = $deliveries->first(fn ($delivery) => $delivery->destination_key === 'primary');
        $primaryVerified = $primaryDelivery?->verification_status === 'verified';
        $reconciliationRequired = $deliveries->contains(fn ($delivery) => $delivery->reconciliation_status === 'reconciliation_required');
        $encrypted = (string) config('platform_backup.encryption.current_key_id') !== '';
        $escrowed = (bool) app(OwnerSettings::class)->get('backups.app_key_escrow_attested');
        $state = match ($health) {
            PlatformRecoveryHealth::Healthy => HealthStatus::Healthy,
            PlatformRecoveryHealth::NotConfigured => HealthStatus::Unknown,
            PlatformRecoveryHealth::Unavailable, PlatformRecoveryHealth::VerificationFailed => HealthStatus::Critical,
            default => HealthStatus::Attention,
        };
        $reason = match ($health) {
            PlatformRecoveryHealth::Healthy => $encrypted && $escrowed ? MonitoringReason::MONITORING_OK : MonitoringReason::PLATFORM_RECOVERY_DEGRADED,
            PlatformRecoveryHealth::NotConfigured => MonitoringReason::MONITORING_SOURCE_UNVERIFIED,
            PlatformRecoveryHealth::Unavailable, PlatformRecoveryHealth::VerificationFailed => MonitoringReason::PLATFORM_RECOVERY_DEGRADED,
            default => MonitoringReason::PLATFORM_BACKUP_STALE,
        };
        if ($health === PlatformRecoveryHealth::Healthy && (! $primaryVerified || $reconciliationRequired || ! $encrypted || ! $escrowed)) {
            $state = HealthStatus::Attention;
        }

        $engine->record(new MonitoringObservation(
            'platform.recovery', MonitoringScope::Platform, 'platform', 'backup-readiness', $state,
            $state === HealthStatus::Critical ? MonitoringSeverity::Critical : ($state === HealthStatus::Attention ? MonitoringSeverity::Warning : null),
            $reason, 'platform_backup', [
                'recovery_health' => $health->value,
                'primary_verified' => $primaryVerified,
                'reconciliation_required' => $reconciliationRequired,
                'encryption_key_configured' => $encrypted,
                'app_key_escrow_attested' => $escrowed,
            ], $latest?->verified_at, 1800, 1, 1,
        ));
    }

    private function storageProvider(MonitoringEngine $engine): void
    {
        $settings = app(OwnerSettings::class)->group('backups');
        $configured = ($settings['backups.primary.enabled'] ?? false)
            && ($settings['backups.primary.storage_driver'] ?? '') === 's3';
        $event = $configured ? AdminAuditEvent::query()->where('event', 'platform_backup.s3_connection_tested')->latest('id')->first() : null;
        $host = parse_url((string) ($settings['backups.primary.endpoint'] ?? ''), PHP_URL_HOST);
        $matches = $event !== null
            && data_get($event->data, 'bucket') === ($settings['backups.primary.bucket'] ?? null)
            && data_get($event->data, 'region') === ($settings['backups.primary.region'] ?? null)
            && data_get($event->data, 'endpoint_host') === $host
            && data_get($event->data, 'path_style') === ($settings['backups.primary.path_style'] ?? null);
        $recent = $matches && $event->created_at?->greaterThan(now()->subHour());
        $passed = $recent && data_get($event->data, 'result') === 'passed';
        $failed = $recent && data_get($event->data, 'result') === 'failed';
        $state = $passed ? HealthStatus::Healthy : ($failed ? HealthStatus::Attention : HealthStatus::Unknown);
        $engine->record(new MonitoringObservation(
            'provider.s3', MonitoringScope::Provider, 'provider', 'platform-backups', $state,
            $failed ? MonitoringSeverity::Warning : null,
            $failed ? MonitoringReason::PROVIDER_S3_UNAVAILABLE : ($passed ? MonitoringReason::MONITORING_OK : MonitoringReason::MONITORING_SOURCE_UNVERIFIED),
            'owner_connection_test', ['configured' => (bool) $configured, 'recently_verified' => (bool) $passed],
            $event?->created_at, 3600, 1, 1,
        ));
    }

    private function mailProvider(MonitoringEngine $engine): void
    {
        $recentFailure = MonitoringAlertDelivery::query()->where('channel', 'email')
            ->where('status', 'failed')->where('updated_at', '>=', now()->subHour())->exists();
        $interrupted = MonitoringAlertDelivery::query()->where('channel', 'email')
            ->where('status', 'sending')->where('last_attempt_at', '<=', now()->subMinutes(10))->exists();
        $lastSent = MonitoringAlertDelivery::query()->where('channel', 'email')->where('status', 'sent')->latest('sent_at')->value('sent_at');
        $recentlyVerified = $lastSent !== null && Carbon::parse($lastSent)->greaterThan(now()->subHours(24));
        $state = $recentFailure || $interrupted ? HealthStatus::Attention : ($recentlyVerified ? HealthStatus::Healthy : HealthStatus::Unknown);
        $engine->record(new MonitoringObservation(
            'provider.mail', MonitoringScope::Provider, 'provider', 'transactional-email', $state,
            $state === HealthStatus::Attention ? MonitoringSeverity::Warning : null,
            $state === HealthStatus::Attention ? MonitoringReason::PROVIDER_MAIL_UNAVAILABLE : ($recentlyVerified ? MonitoringReason::MONITORING_OK : MonitoringReason::MONITORING_STALE),
            'delivery_outbox', ['recent_failure' => $recentFailure, 'interrupted_delivery' => $interrupted, 'recently_verified' => $recentlyVerified],
            $lastSent ? Carbon::parse($lastSent) : null, 3600, 1, 1, false,
        ));
    }

    private function stripeProvider(MonitoringEngine $engine): void
    {
        $verification = Cache::get(SystemStatus::STRIPE_VERIFICATION);
        $at = is_array($verification) && isset($verification['at'])
            ? rescue(fn () => Carbon::parse((string) $verification['at']), null, report: false)
            : null;
        $recent = $at !== null && $at->greaterThan(now()->subHours(24));
        $ok = $recent && (bool) ($verification['ok'] ?? false);
        $failed = $recent && ! (bool) ($verification['ok'] ?? true);
        $state = $ok ? HealthStatus::Healthy : ($failed ? HealthStatus::Attention : HealthStatus::Unknown);
        $engine->record(new MonitoringObservation(
            'provider.stripe', MonitoringScope::Provider, 'provider', 'billing', $state,
            $failed ? MonitoringSeverity::Warning : null,
            $failed ? MonitoringReason::PROVIDER_STRIPE_DEGRADED : ($ok ? MonitoringReason::MONITORING_OK : MonitoringReason::MONITORING_SOURCE_UNVERIFIED),
            'owner_price_verification', ['recently_verified' => $ok, 'problem_count' => $recent ? (int) ($verification['problems'] ?? 0) : null],
            $at, 3600, 1, 1,
        ));
    }

    private function security(MonitoringEngine $engine): void
    {
        $available = app(VulnerabilitySource::class)->available();
        $lastScan = SecurityScan::query()->whereIn('status', ['completed', 'partial'])->latest('completed_at')->first();
        $scanFresh = $lastScan?->completed_at?->greaterThan(now()->subDays(30)) ?? false;
        $feedState = $available ? HealthStatus::Healthy : HealthStatus::Attention;
        $engine->record(new MonitoringObservation(
            'platform.security-feed', MonitoringScope::Platform, 'platform', 'vulnerability-feed', $feedState,
            $available ? null : MonitoringSeverity::Warning,
            $available ? MonitoringReason::MONITORING_OK : MonitoringReason::SECURITY_FEED_STALE,
            'vulnerability_source', ['available' => $available], now(), 86400, 1, 1,
        ));
        $scanState = $lastScan === null ? HealthStatus::Unknown : ($scanFresh ? HealthStatus::Healthy : HealthStatus::Attention);
        $engine->record(new MonitoringObservation(
            'platform.security-scan', MonitoringScope::Platform, 'platform', 'latest-scan', $scanState,
            $scanState === HealthStatus::Attention ? MonitoringSeverity::Warning : null,
            $scanState === HealthStatus::Attention ? MonitoringReason::SECURITY_SCAN_OVERDUE : ($scanState === HealthStatus::Unknown ? MonitoringReason::MONITORING_STALE : MonitoringReason::MONITORING_OK),
            'security_scan', ['scan_status' => $lastScan?->status], $lastScan?->completed_at, 1800, 1, 1,
        ));
    }

    private function workers(MonitoringEngine $engine): void
    {
        foreach ((array) config('monitoring.worker_queues', []) as $name => $settings) {
            $result = MonitoringResult::query()->where('check_id', 'platform.worker')
                ->where('scope', MonitoringScope::Platform->value)->where('subject_type', 'queue')->where('subject_id', $name)->first();
            $stale = ! $result || $result->observed_at?->lt(now()->subMinutes(15));
            $engine->record(new MonitoringObservation(
                'platform.worker', MonitoringScope::Platform, 'queue', (string) $name,
                $stale ? HealthStatus::Critical : HealthStatus::Healthy,
                $stale ? MonitoringSeverity::Critical : null,
                $stale ? MonitoringReason::WORKER_STALE : MonitoringReason::MONITORING_OK,
                'queue', ['pulse_age_seconds' => $result ? now()->diffInSeconds($result->observed_at) : null], $result?->observed_at,
                900, (int) config('monitoring.confirmation.worker_failures', 2), 2,
            ));
            RecordWorkerPulse::dispatch((string) $name)->onConnection((string) ($settings['connection'] ?? config('queue.default')));
        }
    }

    private function pipeline(MonitoringEngine $engine): void
    {
        $last = Cache::get('monitoring.last_probe_completed_at');
        $at = is_string($last) ? Carbon::parse($last) : null;
        $siteQuery = Website::query();
        if (config('app.deployment_environment') === 'staging') {
            $siteQuery->where('uuid', (string) config('synthetic_fixtures.site_uuid'))->whereHas('syntheticFixture', fn ($query) => $query
                ->where('purpose', config('synthetic_fixtures.purpose'))->where('environment', 'staging')->where('enabled', true)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())));
        }
        $sites = $siteQuery->count();
        $stale = $sites > 0 && (! $at || $at->lt(now()->subMinutes(15)));
        $engine->record(new MonitoringObservation(
            'monitoring.pipeline', MonitoringScope::Platform, 'platform', 'probe-worker',
            $stale ? HealthStatus::Critical : ($sites === 0 ? HealthStatus::Unknown : HealthStatus::Healthy),
            $stale ? MonitoringSeverity::Critical : null,
            $stale ? MonitoringReason::MONITORING_STALE : ($sites === 0 ? MonitoringReason::MONITORING_STALE : MonitoringReason::MONITORING_OK),
            'queue', ['last_probe_completed_at' => $at?->toIso8601String(), 'managed_site_count' => $sites], $at, 900, 2, 2,
        ));
    }
}
