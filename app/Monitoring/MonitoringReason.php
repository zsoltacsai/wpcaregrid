<?php

namespace App\Monitoring;

final class MonitoringReason
{
    public const HTTP_UNREACHABLE = 'http.unreachable';

    public const HTTP_TIMEOUT = 'http.timeout';

    public const HTTP_BAD_STATUS = 'http.bad_status';

    public const HTTP_REDIRECT_FAILURE = 'http.redirect_failure';

    public const HTTP_TLS_FAILED = 'http.tls_failed';

    public const HTTP_RESPONSE_TOO_LARGE = 'http.response_too_large';

    public const TLS_EXPIRING = 'tls.expiring';

    public const TLS_EXPIRED = 'tls.expired';

    public const TLS_HOSTNAME_MISMATCH = 'tls.hostname_mismatch';

    public const TLS_INVALID_CHAIN = 'tls.invalid_chain';

    public const TLS_METADATA_UNAVAILABLE = 'tls.metadata_unavailable';

    public const CONNECTOR_DELAYED = 'connector.delayed';

    public const CONNECTOR_STALE = 'connector.stale';

    public const CONNECTOR_DISCONNECTED = 'connector.disconnected';

    public const CONNECTOR_INCOMPATIBLE = 'connector.incompatible';

    public const WORDPRESS_REST_FAILED = 'wordpress.rest_failed';

    public const WORDPRESS_HEALTH_DEGRADED = 'wordpress.health_degraded';

    public const WORDPRESS_MAINTENANCE = 'wordpress.maintenance';

    public const CRON_OVERDUE = 'cron.overdue';

    public const DISK_LOW = 'disk.low';

    public const RUNTIME_MEMORY_LOW = 'runtime.memory_low';

    public const BACKUP_STALE = 'backup.stale';

    public const BACKUP_FAILED = 'backup.failed';

    public const BACKUP_STUCK = 'backup.stuck';

    public const BACKUP_STORAGE_UNAVAILABLE = 'backup.storage_unavailable';

    public const PLATFORM_BACKUP_STALE = 'platform.backup_stale';

    public const PLATFORM_RECOVERY_DEGRADED = 'platform.recovery_degraded';

    public const QUEUE_BACKLOG = 'queue.backlog';

    public const WORKER_STALE = 'worker.stale';

    public const SCHEDULER_STALE = 'scheduler.stale';

    public const DATABASE_UNAVAILABLE = 'database.unavailable';

    public const SECURITY_SCAN_OVERDUE = 'security.scan_overdue';

    public const SECURITY_FINDINGS_OPEN = 'security.findings_open';

    public const SECURITY_FEED_STALE = 'security.feed_stale';

    public const SECURITY_CRITICAL_FINDINGS = 'security.critical_findings';

    public const PROVIDER_S3_UNAVAILABLE = 'provider.s3_unavailable';

    public const PROVIDER_MAIL_UNAVAILABLE = 'provider.mail_unavailable';

    public const PROVIDER_STRIPE_DEGRADED = 'provider.stripe_degraded';

    public const BILLING_WEBHOOK_FAILED = 'billing.webhook_failed';

    public const BILLING_RECONCILIATION_FAILED = 'billing.reconciliation_failed';

    public const BILLING_INVOICE_PROVIDER_UNAVAILABLE = 'billing.invoice_provider_unavailable';

    public const BILLING_MAIL_FAILED = 'billing.mail_failed';

    public const MONITORING_STALE = 'monitoring.pipeline_stale';

    public const MONITORING_SOURCE_UNVERIFIED = 'monitoring.source_unverified';

    public const MONITORING_OK = 'monitoring.ok';

    /** @return list<string> */
    public static function all(): array
    {
        $reflection = new \ReflectionClass(self::class);

        return array_values($reflection->getConstants());
    }

    public static function valid(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    public static function label(string $code): string
    {
        $labels = __('m10.reasons');

        return is_array($labels) && isset($labels[$code]) ? (string) $labels[$code] : $code;
    }
}
