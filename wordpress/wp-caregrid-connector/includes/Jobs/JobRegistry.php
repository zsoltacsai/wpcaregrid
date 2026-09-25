<?php

namespace WPCareGrid\Connector\Jobs;

use WPCareGrid\Connector\Backups\DatabaseDumper;

/**
 * The fixed list of jobs this connector will run. Anything not listed here
 * is refused, whatever the server sends. There is deliberately no job for
 * running PHP, shell commands, SQL or WordPress hooks, and the list is not
 * filterable.
 */
final class JobRegistry
{
    /** @param array<string, JobHandler> $handlers */
    public function __construct(private readonly array $handlers) {}

    public const TYPES = [
        'GET_INVENTORY', 'SITE_HEALTH', 'LICENSE_CHECK',
        'UPDATE_PLUGIN', 'UPDATE_THEME', 'UPDATE_CORE',
        'BACKUP_FILES', 'BACKUP_DATABASE', 'BACKUP_FULL',
        'RESTORE',
        'CREATE_ADMIN_SESSION',
    ];

    /**
     * Capabilities advertised to WP CareGrid: only jobs this site can
     * actually run. Database backups and restores need MySQL/MariaDB.
     */
    public static function capabilities(): array
    {
        $capabilities = ['inventory', 'health', 'updates', 'license_check', 'backup_files', 'restore_files', 'admin_access'];

        if (DatabaseDumper::supported()) {
            array_push($capabilities, 'backup_database', 'backup_full', 'restore_database', 'restore_full');
        }

        return $capabilities;
    }

    public function handler(string $type): ?JobHandler
    {
        return in_array($type, self::TYPES, true) ? ($this->handlers[$type] ?? null) : null;
    }
}
