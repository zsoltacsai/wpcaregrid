<?php

namespace App\Owner;

use App\Backups\BackupStatus;
use App\Backups\Storage\StorageManager;
use App\Cloudflare\CloudflareDnsException;
use App\Cloudflare\CloudflareDnsService;
use App\Connector\HealthStatus;
use App\Models\Backup;
use App\Models\Connector;
use App\Models\SecurityScan;
use App\Models\Website;
use App\Operator\ConnectorState;
use App\Pricing\PricingCatalog;
use App\Security\ScanStatus;
use App\Security\Vulnerabilities\VulnerabilitySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The owner's system page: is each part configured and working. Reports
 * states, drivers and counts only. Secret values (keys, passwords, tokens,
 * storage credentials) are read solely to tell whether they are set, and
 * never returned, logged or shown. Nothing here calls Stripe or backup storage;
 * the Stripe price check is a separate, explicit action.
 */
final class SystemStatus
{
    public const SCHEDULER_HEARTBEAT = 'system.scheduler_heartbeat';

    public const STRIPE_VERIFICATION = 'system.stripe_verification';

    public function __construct(
        private readonly StorageManager $storage,
        private readonly VulnerabilitySource $vulnerabilities,
    ) {}

    /** @return list<StatusCheck> */
    public function checks(): array
    {
        return [
            $this->application(),
            $this->database(),
            $this->queue(),
            $this->scheduler(),
            $this->connectorApi(),
            $this->stripe(),
            $this->cloudflare(),
            $this->backupStorage(),
            $this->securityScanner(),
            $this->mail(),
            $this->safetySwitches(),
        ];
    }

    private function application(): StatusCheck
    {
        $production = app()->environment('production');
        $debug = (bool) config('app.debug');
        $key = filled(config('app.key'));
        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $https = str_starts_with($url, 'https://');
        $localUrl = $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
        $secureCookie = (bool) config('session.secure');

        return new StatusCheck('application', match (true) {
            ! $key || ($production && ($debug || ! $https || $localUrl)) => HealthStatus::Critical,
            $debug || ($production && ! $secureCookie) => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        }, match (true) {
            ! $key => __('owner.system.summaries.app_key_missing'),
            $production && $debug => __('owner.system.summaries.debug_in_production'),
            $production && $localUrl => __('owner.system.summaries.app_url_local'),
            $production && ! $https => __('owner.system.summaries.app_url_http'),
            $production && ! $secureCookie => __('owner.system.summaries.cookie_not_secure'),
            default => __('owner.system.summaries.running', ['env' => app()->environment()]),
        }, [
            __('owner.system.details.environment') => (string) app()->environment(),
            __('owner.system.details.debug') => $this->onOff($debug),
            __('owner.system.details.app_key') => $this->configured($key),
            __('owner.system.details.https') => $https ? __('owner.system.values.yes') : __('owner.system.values.no'),
            __('owner.system.details.secure_cookie') => $this->onOff($secureCookie),
            __('owner.system.details.php') => PHP_VERSION,
            __('owner.system.details.laravel') => app()->version(),
        ]);
    }

    private function database(): StatusCheck
    {
        try {
            DB::select('select 1');
            $migrator = app('migrator');
            $ran = $migrator->getRepository()->getRan();
            $pending = count(array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $ran));
        } catch (Throwable) {
            return new StatusCheck('database', HealthStatus::Critical, __('owner.system.summaries.database_unreachable'), [
                __('owner.system.details.driver') => (string) config('database.connections.'.config('database.default').'.driver'),
            ]);
        }

        return new StatusCheck('database', $pending > 0 ? HealthStatus::Attention : HealthStatus::Healthy, $pending > 0
            ? __('owner.system.summaries.migrations_pending', ['count' => $pending])
            : __('owner.system.summaries.connected'), [
                __('owner.system.details.driver') => (string) DB::connection()->getDriverName(),
                __('owner.system.details.pending_migrations') => (string) $pending,
            ]);
    }

    private function queue(): StatusCheck
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");
        $failed = $this->safeCount(fn () => DB::table((string) config('queue.failed.table', 'failed_jobs'))->count());
        $oldest = $driver === 'database'
            ? $this->safe(fn () => DB::table((string) config("queue.connections.{$connection}.table", 'jobs'))
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->timestamp)
                ->min('available_at'))
            : null;
        $waiting = $oldest ? (int) now()->diffInMinutes(CarbonImmutable::createFromTimestamp((int) $oldest), true) : 0;

        // Backup transfers must not be picked up by a second worker while they still run.
        $transferConnection = (string) config('backup.transfer.connection');
        $transferSafe = $driver === 'sync' || (config("queue.connections.{$transferConnection}") !== null
            && (int) config("queue.connections.{$transferConnection}.retry_after") > (int) config('backup.transfer.timeout'));

        $status = match (true) {
            ! $transferSafe => HealthStatus::Critical,
            $driver === 'sync' && app()->environment('production') => HealthStatus::Attention,
            $waiting > 15 => HealthStatus::Critical,
            $failed > 0 => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        };

        return new StatusCheck('queue', $status, match (true) {
            ! $transferSafe => __('owner.system.summaries.transfer_queue_unsafe'),
            $waiting > 15 => __('owner.system.summaries.queue_stuck', ['minutes' => $waiting]),
            $failed > 0 => __('owner.system.summaries.failed_jobs', ['count' => $failed]),
            default => __('owner.system.summaries.queue_ok', ['driver' => $driver]),
        }, [
            __('owner.system.details.driver') => $driver,
            __('owner.system.details.failed_jobs') => (string) $failed,
            __('owner.system.details.backup_queue') => $driver === 'sync' ? 'sync' : $transferConnection.' / '.config('backup.transfer.queue'),
        ]);
    }

    private function scheduler(): StatusCheck
    {
        $beat = Cache::get(self::SCHEDULER_HEARTBEAT);
        $last = is_string($beat) ? CarbonImmutable::parse($beat) : null;

        return new StatusCheck('scheduler', match (true) {
            $last === null => HealthStatus::Unknown,
            $last->lt(now()->subMinutes(5)) => HealthStatus::Critical,
            default => HealthStatus::Healthy,
        }, match (true) {
            $last === null => __('owner.system.summaries.scheduler_never'),
            $last->lt(now()->subMinutes(5)) => __('owner.system.summaries.scheduler_stopped'),
            default => __('owner.system.summaries.scheduler_running'),
        }, [
            __('owner.system.details.last_run') => $last?->format('Y-m-d H:i') ?? '—',
        ], notConfigured: $last === null);
    }

    private function connectorApi(): StatusCheck
    {
        $count = function (ConnectorState $state): int {
            $query = Website::query();
            $state->scope($query);

            return $query->count();
        };
        $connected = $count(ConnectorState::Connected);
        $offline = $count(ConnectorState::Stale) + $count(ConnectorState::Disconnected);
        $paired = Connector::where('status', Connector::ACTIVE)->count();

        return new StatusCheck('connector_api', match (true) {
            $paired === 0 => HealthStatus::Unknown,
            $offline > 0 => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        }, match (true) {
            $paired === 0 => __('owner.system.summaries.no_connectors'),
            $offline > 0 => __('owner.system.summaries.connectors_offline', ['count' => $offline]),
            default => __('owner.system.summaries.connectors_reporting'),
        }, [
            __('owner.system.details.connected') => (string) $connected,
            __('owner.system.details.offline') => (string) $offline,
            __('owner.system.details.latest_connector') => (string) config('connector.latest_version'),
        ]);
    }

    private function stripe(): StatusCheck
    {
        $secret = (string) config('services.stripe.secret');
        $mode = match (true) {
            str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'rk_live_') => __('owner.system.values.live'),
            str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'rk_test_') => __('owner.system.values.test'),
            default => '—',
        };

        $catalogValid = true;
        $missingIds = 0;
        try {
            foreach (app(PricingCatalog::class)->allPrices() as $price) {
                $missingIds += $price->active && ! $price->hasStripePrice() ? 1 : 0;
            }
        } catch (Throwable) {
            $catalogValid = false;
        }

        $verification = Cache::get(self::STRIPE_VERIFICATION);
        $configured = filled($secret) && filled(config('services.stripe.webhook_secret'));

        return new StatusCheck('stripe', match (true) {
            ! $configured || ! $catalogValid => HealthStatus::Critical,
            $missingIds > 0 || (is_array($verification) && ! $verification['ok']) => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        }, match (true) {
            ! $catalogValid => __('owner.system.summaries.catalog_invalid'),
            ! $configured => __('owner.system.summaries.stripe_not_configured'),
            $missingIds > 0 => __('owner.system.summaries.price_ids_missing', ['count' => $missingIds]),
            default => __('owner.system.summaries.configured'),
        }, [
            __('owner.system.details.secret_key') => $this->configured(filled($secret)),
            __('owner.system.details.publishable_key') => $this->configured(filled(config('services.stripe.key'))),
            __('owner.system.details.webhook_secret') => $this->configured(filled(config('services.stripe.webhook_secret'))),
            __('owner.system.details.mode') => $mode,
            __('owner.system.details.catalog') => $catalogValid ? __('owner.system.values.valid') : __('owner.system.values.invalid'),
            __('owner.system.details.missing_price_ids') => (string) $missingIds,
            __('owner.system.details.last_verification') => is_array($verification)
                ? $verification['at'].' · '.($verification['ok'] ? __('owner.system.values.ok') : __('owner.system.values.problems', ['count' => $verification['problems']]))
                : '—',
        ], notConfigured: ! $configured);
    }

    private function cloudflare(): StatusCheck
    {
        $token = filled(config('services.cloudflare.api_token'));
        if (! $token) {
            return new StatusCheck('cloudflare', HealthStatus::Unknown, __('owner.system.summaries.cloudflare_not_configured'), [
                __('owner.system.details.credentials') => __('owner.system.values.not_configured'),
            ], notConfigured: true, optional: true);
        }

        try {
            $diagnostic = app(CloudflareDnsService::class)->diagnose();

            return new StatusCheck('cloudflare', HealthStatus::Healthy, __('owner.system.summaries.cloudflare_connected'), [
                __('owner.system.details.credentials') => __('owner.system.values.configured'),
                __('owner.system.details.approved_zones') => (string) count($diagnostic['zones']),
                __('owner.system.details.dns_read') => __('owner.system.values.available'),
                __('owner.system.details.dns_edit') => __('owner.system.values.not_probed'),
            ]);
        } catch (CloudflareDnsException $exception) {
            return new StatusCheck('cloudflare', HealthStatus::Attention, __('owner.system.summaries.cloudflare_unavailable'), [
                __('owner.system.details.credentials') => __('owner.system.values.configured'),
                __('owner.system.details.cloudflare_error') => __('owner.dns.errors.'.$exception->category),
            ]);
        }
    }

    private function backupStorage(): StatusCheck
    {
        $name = $this->storage->defaultName();
        $selected = (string) config('backup.storage');
        $configurationIssue = $selected !== '' ? $this->storage->configurationIssue($selected) : null;
        $verified = $name ? Backup::where('status', BackupStatus::Verified->value)->where('verified_at', '>=', now()->subDays(7))->exists() : false;

        // Uploads are staged on this server before they go to storage: enough free space?
        $staging = (string) config('backup.upload.staging_path');
        $probe = $staging;
        while ($probe !== '' && ! is_dir($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        $free = is_dir($probe) ? @disk_free_space($probe) : false;
        $needed = (int) config('backup.upload.staging_min_free_mb') * 1024 * 1024;
        $tight = $free !== false && $free < $needed;

        return new StatusCheck('backup_storage', match (true) {
            $name === null => HealthStatus::Critical,
            $tight => HealthStatus::Attention,
            $name === 'local' && app()->environment('production') => HealthStatus::Attention,
            ! $verified => HealthStatus::Unknown,
            default => HealthStatus::Healthy,
        }, match (true) {
            $name === null => $configurationIssue ?? ($selected === '' ? __('owner.system.summaries.storage_not_selected') : __('owner.system.summaries.storage_incomplete')),
            $tight => __('owner.system.summaries.staging_low', ['free' => $this->gib($free), 'needed' => $this->gib($needed)]),
            $name === 'local' && app()->environment('production') => __('owner.system.summaries.storage_local'),
            ! $verified => __('owner.system.summaries.storage_no_recent'),
            default => __('owner.system.summaries.storage_connected'),
        }, [
            __('owner.system.details.provider') => $name ?? ($selected !== '' ? $selected : '—'),
            __('owner.system.details.credentials') => $this->configured($name !== null),
            __('owner.system.details.verified_7_days') => $verified ? __('owner.system.values.yes') : __('owner.system.values.no'),
            __('owner.system.details.staging_free') => $free === false ? '—' : $this->gib($free),
        ], notConfigured: $name === null && $selected === '');
    }

    private function securityScanner(): StatusCheck
    {
        $vulnerabilities = $this->vulnerabilities->available();
        $last = SecurityScan::whereIn('status', [ScanStatus::Completed->value, ScanStatus::Partial->value])->latest('completed_at')->value('completed_at');

        return new StatusCheck('security_scanner', match (true) {
            $last === null => HealthStatus::Unknown,
            ! $vulnerabilities => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        }, match (true) {
            $last === null => __('owner.system.summaries.no_scans'),
            ! $vulnerabilities => __('owner.system.summaries.vulnerabilities_not_configured'),
            default => __('owner.system.summaries.scanner_ok'),
        }, [
            __('owner.system.details.scanner_version') => (string) config('security.scanner_version'),
            __('owner.system.details.vulnerability_source') => $vulnerabilities ? $this->vulnerabilities->name() : __('owner.system.values.not_configured'),
            __('owner.system.details.last_scan') => $last ? CarbonImmutable::parse($last)->format('Y-m-d H:i') : '—',
            __('owner.system.details.remediation') => $this->onOff((bool) config('security.remediation.enabled')),
        ]);
    }

    private function mail(): StatusCheck
    {
        $mailer = (string) config('mail.default');
        $driver = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $from = filled(config('mail.from.address'));
        $local = in_array($driver, ['log', 'array'], true);

        return new StatusCheck('mail', match (true) {
            $local && app()->environment('production') => HealthStatus::Critical,
            $local || ! $from => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        }, match (true) {
            $local => __('owner.system.summaries.mail_not_sent', ['driver' => $driver]),
            ! $from => __('owner.system.summaries.mail_no_from'),
            default => __('owner.system.summaries.configured'),
        }, [
            __('owner.system.details.driver') => $driver,
            __('owner.system.details.from_address') => $this->configured($from),
        ], notConfigured: $local);
    }

    /**
     * The switches that allow changes on customer sites. Conservative by
     * default; anything turned on is shown so it is a deliberate decision.
     */
    private function safetySwitches(): StatusCheck
    {
        $switches = [
            'remediation' => (bool) config('security.remediation.enabled'),
            'operator_full_admin' => (bool) config('admin_access.operator_full_admin'),
            'restore' => (bool) config('backup.restore.enabled'),
            'plugin_deactivation' => (bool) config('security.remediation.allow_disable_plugin'),
        ];
        $enabled = array_keys(array_filter($switches));

        return new StatusCheck('safety', $enabled === [] ? HealthStatus::Healthy : HealthStatus::Attention, $enabled === []
            ? __('owner.system.summaries.safety_conservative')
            : __('owner.system.summaries.safety_enabled', ['switches' => implode(', ', array_map(fn ($s) => __('owner.system.switches.'.$s), $enabled))]),
            collect($switches)->mapWithKeys(fn ($on, $switch) => [__('owner.system.switches.'.$switch) => $this->onOff($on)])->all());
    }

    private function gib(int|float $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 1).' GiB';
    }

    private function configured(bool $set): string
    {
        return $set ? __('owner.system.values.configured') : __('owner.system.values.not_configured');
    }

    private function onOff(bool $on): string
    {
        return $on ? __('owner.system.values.on') : __('owner.system.values.off');
    }

    private function safe(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }

    private function safeCount(callable $read): int
    {
        return (int) ($this->safe($read) ?? 0);
    }
}
