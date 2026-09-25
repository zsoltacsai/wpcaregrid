<?php

namespace App\Connector;

/**
 * What a connector can do. A connector advertises only what it has
 * implemented; the server only sends jobs the connector advertised.
 * Security capabilities are reserved for a later milestone.
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

    /** @param  array<mixed>  $values  Unknown values are dropped. */
    public static function listFrom(array $values): array
    {
        return array_values(array_unique(array_map(
            fn (self $c) => $c->value,
            array_filter(array_map(fn ($v) => is_string($v) ? self::tryFrom($v) : null, $values)),
        )));
    }
}
