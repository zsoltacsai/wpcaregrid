<?php

namespace App\Events\Platform;

/** Machine-readable operational signal for future M10 monitoring integration. */
final readonly class PlatformRecoverySignal
{
    public const DATABASE_BACKUP_FAILED = 'platform_db_backup_failed';

    public const DATABASE_BACKUP_VERIFICATION_FAILED = 'platform_db_backup_verification_failed';

    public const STORAGE_UNAVAILABLE = 'storage_unavailable';

    public const STORAGE_RECONCILIATION_ANOMALY = 'storage_reconciliation_anomaly';

    public const RETENTION_CLEANUP_FAILED = 'retention_cleanup_failed';

    public const DR_DRILL_FAILED = 'dr_drill_failed';

    public const SECRET_RECOVERY_PREREQUISITE_MISSING = 'secret_recovery_prerequisite_missing';

    public const BILLING_RECONCILIATION_FAILED = 'billing.reconciliation_failed';

    /** @param array<string, scalar|null> $context */
    public function __construct(public string $type, public array $context = []) {}
}
