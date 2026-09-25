<?php

namespace WPCareGrid\Connector\Backups;

/**
 * What a files backup contains ("standard-1"):
 *
 *   wp-content/…   everything in WP_CONTENT_DIR, except the exclusions below
 *   root/…         .htaccess, .user.ini, web.config, robots.txt from ABSPATH
 *   root/wp-config.php   with the database user and password, keys and
 *                  salts removed (WpConfigRedactor); left out entirely if
 *                  that cannot be guaranteed
 *
 * Not included: WordPress core (wp-admin, wp-includes, root wp-*.php); it
 * is reinstalled for the version in the manifest. Nothing outside the
 * installation, and symlinks are never followed.
 *
 * Excluded inside wp-content: caches, upgrade leftovers, other backup
 * plugins' archives, logs, temporary and editor files, VCS and
 * node_modules folders, the connector's own temporary directory, and files
 * over the size limit (reported as skipped).
 */
final class FilePolicy
{
    public const NAME = 'standard-1';

    public const ROOT_FILES = ['.htaccess', '.user.ini', 'web.config', 'robots.txt'];

    /** Paths relative to wp-content. */
    private const EXCLUDED_DIRS = [
        'cache', 'upgrade', 'upgrade-temp-backup', 'backup', 'backups', 'backup-db', 'backups-dup-lite', 'backups-dup-pro',
        'updraft', 'ai1wm-backups', 'wpvividbackups', 'wp-snapshots', 'et-cache', 'litespeed', 'wflogs',
        'uploads/cache', 'uploads/wc-logs', 'uploads/wp-migrate-db', 'uploads/et_temp',
    ];

    private const EXCLUDED_DIR_PATTERNS = [
        '#^uploads/backwpup-#',
        '#^wp-caregrid-[a-z0-9]+$#',
        '#(^|/)(\.git|\.svn|\.hg|node_modules)$#',
    ];

    private const EXCLUDED_FILE_PATTERNS = [
        '#\.log$#i',
        '#(^|/)error_log$#',
        '#\.(tmp|temp|swp|swo)$#i',
        '#(^|/)(\.DS_Store|Thumbs\.db)$#',
        '#(^|/)core\.\d+$#',
    ];

    public static function max_file_size(): int
    {
        return (int) apply_filters('wp_caregrid_backup_max_file_size', 1024 * 1024 * 1024);
    }

    /** @param  string  $relative  Path relative to wp-content. */
    public static function excludes_dir(string $relative): bool
    {
        if (in_array($relative, self::EXCLUDED_DIRS, true)) {
            return true;
        }

        foreach (self::EXCLUDED_DIR_PATTERNS as $pattern) {
            if (preg_match($pattern, $relative)) {
                return true;
            }
        }

        // The connector's temporary directory, wherever it is.
        return TempDir::inside(WP_CONTENT_DIR.'/'.$relative, TempDir::path());
    }

    public static function excludes_file(string $relative): bool
    {
        foreach (self::EXCLUDED_FILE_PATTERNS as $pattern) {
            if (preg_match($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }

    /** Where an archive entry goes back to on restore, or null when it is never restored in place. */
    public static function restore_target(string $entry): ?string
    {
        $entry = PathGuard::relative($entry);

        if (str_starts_with($entry, 'wp-content/')) {
            $relative = substr($entry, strlen('wp-content/'));

            // The connector keeps running its current version; its temp dir is its own.
            if ($relative === 'plugins/wp-caregrid-connector' || str_starts_with($relative, 'plugins/wp-caregrid-connector/') || self::excludes_dir(explode('/', $relative)[0])) {
                return null;
            }

            return PathGuard::join(WP_CONTENT_DIR, $relative);
        }

        if (str_starts_with($entry, 'root/') && in_array(substr($entry, 5), self::ROOT_FILES, true)) {
            return PathGuard::join(ABSPATH, substr($entry, 5));
        }

        // root/wp-config.php holds redacted secrets: it is for disaster recovery, never copied over the live file.
        return null;
    }
}
