<?php

namespace App\Connector;

/**
 * What a connector can do. A connector advertises only what it has
 * implemented; the server only sends jobs the connector advertised.
 * Security scanning, quarantine and file repair come with connector 0.4.0.
 */
enum Capability: string
{
    case Inventory = 'inventory';
    case Health = 'health';
    case Updates = 'updates';
    case LicenseCheck = 'license_check';
    case BackupFiles = 'backup_files';
    case BackupDatabase = 'backup_database';
    case BackupFull = 'backup_full';
    case RestoreFiles = 'restore_files';
    case RestoreDatabase = 'restore_database';
    case RestoreFull = 'restore_full';
    case AdminAccess = 'admin_access';
    case SecurityScan = 'security_scan';
    case SecurityQuarantine = 'security_quarantine';
    case SecurityRemediate = 'security_remediate';

    /** @param  array<mixed>  $values  Unknown values are dropped. */
    public static function listFrom(array $values): array
    {
        return array_values(array_unique(array_map(
            fn (self $c) => $c->value,
            array_filter(array_map(fn ($v) => is_string($v) ? self::tryFrom($v) : null, $values)),
        )));
    }
}
