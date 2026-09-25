<?php

namespace WPCareGrid\Connector\Backups;

/** Refuse to start when the temporary directory's disk cannot hold the work. */
final class DiskSpace
{
    private const MARGIN = 50 * 1024 * 1024;

    public static function require(string $dir, int $bytes): void
    {
        $free = function_exists('disk_free_space') ? @disk_free_space($dir) : false;
        $free = apply_filters('wp_caregrid_backup_free_space', $free, $dir);

        // Unknown (disabled function): writes are still checked as they happen.
        if ($free !== false && $free < $bytes + self::MARGIN) {
            throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'Not enough free disk space for the backup.', ['needed' => $bytes + self::MARGIN, 'free' => (int) $free]);
        }
    }
}
