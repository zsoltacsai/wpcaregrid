<?php

namespace WPCareGrid\Connector\Core;

/**
 * One poll at a time per site. The lock is an option with a timestamp; a
 * lock older than TTL belongs to a process that died. Long jobs (backups,
 * restores) refresh it so no second poll starts while they run.
 */
final class Lock
{
    private const OPTION = 'wp_caregrid_poll_lock';

    public const TTL = 900;

    private static int $refreshed = 0;

    public static function acquire(): bool
    {
        $now = time();

        if (add_option(self::OPTION, $now, '', false)) {
            self::$refreshed = $now;

            return true;
        }

        if ((int) get_option(self::OPTION) < $now - self::TTL) {
            update_option(self::OPTION, $now, false);
            self::$refreshed = $now;

            return true;
        }

        return false;
    }

    /** Called often during long work; writes at most once a minute. */
    public static function refresh(): void
    {
        if (time() - self::$refreshed >= 60) {
            self::$refreshed = time();
            update_option(self::OPTION, self::$refreshed, false);
        }
    }

    public static function release(): void
    {
        delete_option(self::OPTION);
    }
}
