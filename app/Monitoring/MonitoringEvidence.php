<?php

namespace App\Monitoring;

/** Tight allowlist for persisted and visible monitoring evidence. */
final class MonitoringEvidence
{
    private const KEYS = [
        'available', 'app_key_escrow_attested', 'configured', 'days_remaining', 'depth', 'finding_count',
        'failed_webhooks', 'failed_reconciliations', 'operations_needing_reconciliation', 'invoice_outcomes_unknown', 'email_delivery_failures', 'source_schema_ready',
        'error_category', 'failed_jobs', 'health_status', 'http_status', 'interrupted_delivery',
        'latency_ms', 'last_heartbeat_at', 'last_probe_completed_at', 'managed_site_count',
        'oldest_age_seconds', 'open_findings', 'presence', 'primary_verified', 'problem_count', 'probes_queued', 'pulse_age_seconds',
        'queue', 'queue_depth_supported', 'queue_driver', 'reconciliation_required', 'recent_failure',
        'recently_verified', 'redirects', 'recovery_health', 'response_truncated', 'scan_status', 'site_health_reason',
        'scenario', 'verified_backup_available', 'encryption_key_configured',
    ];

    /** @param array<string, scalar|null> $evidence */
    public static function assertSafe(array $evidence): void
    {
        foreach ($evidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::KEYS, true)) {
                throw new \InvalidArgumentException('Monitoring evidence contains an unsupported field.');
            }
            if (! is_scalar($value) && $value !== null || is_string($value) && strlen($value) > 512) {
                throw new \InvalidArgumentException('Monitoring evidence must be bounded scalar data.');
            }
        }
    }
}
