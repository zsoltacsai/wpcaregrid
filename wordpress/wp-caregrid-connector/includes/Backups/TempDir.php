<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Jobs\JobStore;

/**
 * Where backup and restore files are built. Prefers a directory outside the
 * web root:
 *   1. WP_CAREGRID_BACKUP_DIR, if defined (recommended for production);
 *   2. the system temp directory (get_temp_dir()), when it is outside ABSPATH;
 *   3. otherwise wp-content/wp-caregrid-<random>, with deny rules for Apache
 *      and IIS and an unguessable name, since other servers ignore those.
 *
 * Directories are 0700, files 0600. File names are deterministic per job
 * ({uuid}-files.tar.gz, …) so an interrupted upload can resume and cleanup
 * knows what belongs to whom.
 */
final class TempDir
{
    private const OPTION = 'wp_caregrid_temp_suffix';

    /** Files of jobs no longer running are removed after this long. */
    private const STALE_AFTER = 6 * HOUR_IN_SECONDS;

    public static function path(): string
    {
        if (defined('WP_CAREGRID_BACKUP_DIR') && WP_CAREGRID_BACKUP_DIR) {
            return rtrim((string) WP_CAREGRID_BACKUP_DIR, '/');
        }

        $suffix = get_option(self::OPTION);
        if (! is_string($suffix) || ! preg_match('/^[a-z0-9]{24}$/', $suffix)) {
            $suffix = strtolower(wp_generate_password(24, false));
            update_option(self::OPTION, $suffix, false);
        }

        $system = rtrim(get_temp_dir(), '/');
        if (! self::inside($system, ABSPATH) && ! self::inside($system, WP_CONTENT_DIR)) {
            return $system.'/wp-caregrid-'.$suffix;
        }

        return rtrim(WP_CONTENT_DIR, '/').'/wp-caregrid-'.$suffix;
    }

    /** @throws BackupFailure */
    public static function ensure(): string
    {
        $dir = self::path();

        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            throw new BackupFailure('TEMP_DIR_UNAVAILABLE', 'The temporary backup directory cannot be created.');
        }

        @chmod($dir, 0700);

        if (self::inside($dir, ABSPATH) || self::inside($dir, WP_CONTENT_DIR)) {
            // Belt and braces for servers that honour them.
            @file_put_contents($dir.'/index.php', "<?php\n// Silence.\n");
            @file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir.'/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        }

        if (! is_writable($dir)) {
            throw new BackupFailure('TEMP_DIR_UNAVAILABLE', 'The temporary backup directory is not writable.');
        }

        return $dir;
    }

    /** A deterministic file name for a job's artefact. */
    public static function file(string $uuid, string $name): string
    {
        if (! preg_match('/^[0-9a-f-]{36}$/', $uuid) || ! preg_match('/^[a-z0-9.-]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid temporary file name.');
        }

        return self::ensure().'/'.$uuid.'-'.$name;
    }

    /** Create the file with 0600 permissions before anything is written to it. */
    public static function create(string $path): void
    {
        $handle = @fopen($path, 'wb');
        if (! $handle) {
            throw new BackupFailure('TEMP_DIR_UNAVAILABLE', 'A temporary backup file cannot be created.');
        }
        fclose($handle);
        @chmod($path, 0600);
    }

    /** Remove everything belonging to one job. */
    public static function remove_job(string $uuid): void
    {
        if (! preg_match('/^[0-9a-f-]{36}$/', $uuid) || ! is_dir(self::path())) {
            return;
        }

        foreach ((array) glob(self::path().'/'.$uuid.'-*') as $path) {
            self::remove((string) $path);
        }
    }

    /**
     * Stale files: older than STALE_AFTER and not belonging to a job that
     * is still running (whose upload may resume). Keeps failed backups from
     * filling the disk.
     */
    public static function cleanup_stale(JobStore $jobs): void
    {
        $dir = self::path();
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir.'/*') as $path) {
            $name = basename((string) $path);
            if (! preg_match('/^([0-9a-f-]{36})-/', $name, $m)) {
                continue;
            }

            $job = $jobs->find($m[1]);
            $running = $job && $job['state'] === JobStore::RUNNING;

            if (! $running && time() - (int) @filemtime((string) $path) > self::STALE_AFTER) {
                self::remove((string) $path);
            }
        }
    }

    public static function inside(string $path, string $root): bool
    {
        $path = rtrim(wp_normalize_path($path), '/').'/';
        $root = rtrim(wp_normalize_path($root), '/').'/';

        return str_starts_with($path, $root);
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (is_dir($path)) {
            foreach ((array) scandir($path) as $item) {
                if ($item !== '.' && $item !== '..') {
                    self::remove($path.'/'.$item);
                }
            }
            @rmdir($path);
        }
    }
}
