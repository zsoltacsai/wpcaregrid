<?php

namespace WPCareGrid\Connector\Backups;

/**
 * WordPress maintenance mode during the critical part of a restore, using
 * WordPress's own .maintenance file. It can never stay on:
 *   1. the restore turns it off in a finally block;
 *   2. a shutdown function turns it off if PHP dies (fatal error, timeout);
 *   3. WordPress itself ignores a .maintenance file older than 10 minutes;
 *   4. every poll removes a leftover that this connector created.
 * The marker lives in the temporary directory, not in the database, so a
 * database restore cannot lose it.
 */
final class Maintenance
{
    private static ?string $active = null;

    public static function file(): string
    {
        return ABSPATH.'.maintenance';
    }

    public static function enable(string $restore_uuid): void
    {
        if (is_file(self::file())) {
            throw new BackupFailure('MAINTENANCE_ACTIVE', 'The site is already in maintenance mode.');
        }

        // The marker first: without it the failsafes could not tell the file is ours.
        TempDir::ensure();
        if (@file_put_contents(self::marker(), wp_json_encode(['restore' => $restore_uuid, 'since' => time()])) === false) {
            throw new BackupFailure('MAINTENANCE_FAILED', 'Maintenance mode could not be enabled.');
        }
        if (@file_put_contents(self::file(), '<?php $upgrading = '.time().';') === false) {
            @unlink(self::marker());
            throw new BackupFailure('MAINTENANCE_FAILED', 'Maintenance mode could not be enabled.');
        }

        if (self::$active === null) {
            register_shutdown_function([self::class, 'disable']);
        }
        self::$active = $restore_uuid;
    }

    /** Only removes a maintenance file this connector created. */
    public static function disable(): void
    {
        if (is_file(self::marker())) {
            @unlink(self::file());
            @unlink(self::marker());
        }
        self::$active = null;
    }

    public static function is_ours(): bool
    {
        return is_file(self::marker()) && is_file(self::file());
    }

    /** A leftover from a process that died: nothing of ours is running now (the poll lock is held). */
    public static function cleanup_stale(): void
    {
        if (self::$active === null && is_file(self::marker())) {
            self::disable();
        }
    }

    private static function marker(): string
    {
        return TempDir::path().'/maintenance.json';
    }
}
