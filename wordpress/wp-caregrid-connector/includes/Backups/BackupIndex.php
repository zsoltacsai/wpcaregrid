<?php

namespace WPCareGrid\Connector\Backups;

/**
 * The connector's own record of the backups it made: UUID, type and the
 * size and SHA-256 of each object. A restore is only accepted for a backup
 * listed here with matching checksums, so the site only ever restores data
 * it produced itself, whatever the server sends. Survives database
 * restores (connector state is preserved).
 */
final class BackupIndex
{
    private const OPTION = 'wp_caregrid_backup_index';

    private const KEEP = 200;

    public static function record(string $uuid, string $type, array $objects): void
    {
        $index = self::all();
        $index[$uuid] = ['type' => $type, 'created_at' => gmdate('c'), 'objects' => $objects];

        if (count($index) > self::KEEP) {
            $index = array_slice($index, -self::KEEP, null, true);
        }

        update_option(self::OPTION, $index, false);
    }

    public static function find(string $uuid): ?array
    {
        return self::all()[$uuid] ?? null;
    }

    public static function all(): array
    {
        $index = get_option(self::OPTION, []);

        return is_array($index) ? $index : [];
    }
}
