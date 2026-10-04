<?php

namespace App\Owner;

use App\Backups\BackupStatus;
use App\Backups\Storage\StorageManager;
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
 * never returned, logged or shown. Nothing here calls Stripe or storage;
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
            $this->backupStorage(),
            $this->securityScanner(),
            $this->mail(),
        ];
    }

    private function application(): StatusCheck
    {
        $production = app()->environment('production');
        $debug = (bool) config('app.debug');
        $key = filled(config('app.key'));

        return new StatusCheck('application', match (true) {
            ! $key || ($production && $debug) => HealthStatus::Critical,
            $debug => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        }, match (true) {
            ! $key => __('owner.system.summaries.app_key_missing'),
            $production && $debug => __('owner.system.summaries.debug_in_production'),
            default => __('owner.system.summaries.running', ['env' => app()->environment()]),
        }, [
            __('owner.system.details.environment') => (string) app()->environment(),
            __('owner.system.details.debug') => $this->onOff($debug),
            __('owner.system.details.app_key') => $this->configured($key),
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
            ? $this->safe(fn () => DB::table((string) config("queue.connections.{$connection}.table", 'jobs'))->whereNull('reserved_at')->min('available_at'))
            : null;
        $waiting = $oldest ? (int) now()->diffInMinutes(CarbonImmutable::createFromTimestamp((int) $oldest), true) : 0;

        $status = match (true) {
            $driver === 'sync' && app()->environment('production') => HealthStatus::Attention,
            $waiting > 15 => HealthStatus::Critical,
            $failed > 0 => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        };

        return new StatusCheck('queue', $status, match (true) {
            $waiting > 15 => __('owner.system.summaries.queue_stuck', ['minutes' => $waiting]),
            $failed > 0 => __('owner.system.summaries.failed_jobs', ['count' => $failed]),
            default => __('owner.system.summaries.queue_ok', ['driver' => $driver]),
        }, [
            __('owner.system.details.driver') => $driver,
            __('owner.system.details.failed_jobs') => (string) $failed,
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
        ]);
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
        ]);
    }

    private function backupStorage(): StatusCheck
    {
        $name = $this->storage->defaultName();
        $selected = (string) config('backup.storage');
        $verified = $name ? Backup::where('status', BackupStatus::Verified->value)->where('verified_at', '>=', now()->subDays(7))->exists() : false;

        return new StatusCheck('backup_storage', match (true) {
            $name === null => HealthStatus::Critical,
            $name === 'local' && app()->environment('production') => HealthStatus::Attention,
            ! $verified => HealthStatus::Unknown,
            default => HealthStatus::Healthy,
        }, match (true) {
            $name === null => $selected === '' ? __('owner.system.summaries.storage_not_selected') : __('owner.system.summaries.storage_incomplete'),
            ! $verified => __('owner.system.summaries.storage_no_recent'),
            default => __('owner.system.summaries.storage_connected'),
        }, [
            __('owner.system.details.provider') => $name ?? ($selected !== '' ? $selected : '—'),
            __('owner.system.details.credentials') => $this->configured($name !== null),
            __('owner.system.details.verified_7_days') => $verified ? __('owner.system.values.yes') : __('owner.system.values.no'),
        ]);
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
        ]);
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
