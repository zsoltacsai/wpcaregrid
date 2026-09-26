<?php

namespace WPCareGrid\Connector\Security\Quarantine;

use WPCareGrid\Connector\Backups\PathGuard;
use WPCareGrid\Connector\Security\Scan\SitePaths;

/**
 * Reversible quarantine. A quarantined file is moved (not copied, not
 * deleted) into the quarantine directory, renamed to "{id}.quarantined" so
 * it has no executable extension, and made 0600; a metadata file keeps the
 * original path, SHA-256, size, modification time, permissions, reason and
 * the finding and remediation it belongs to. WP CareGrid keeps the same
 * record centrally.
 *
 * Location: WP_CAREGRID_QUARANTINE_DIR when defined (recommended: outside
 * the web root), otherwise wp-content/wp-caregrid-quarantine-<random>
 * with deny rules for Apache and IIS. Not the system temp directory, which
 * may be emptied on reboot.
 *
 * Restoring puts the file back exactly where it was, and never over a
 * file that exists there now.
 */
final class QuarantineStore
{
    private const OPTION = 'wp_caregrid_quarantine_suffix';

    public static function path(): string
    {
        if (defined('WP_CAREGRID_QUARANTINE_DIR') && WP_CAREGRID_QUARANTINE_DIR) {
            return rtrim(wp_normalize_path((string) WP_CAREGRID_QUARANTINE_DIR), '/');
        }

        $suffix = get_option(self::OPTION);
        if (! is_string($suffix) || ! preg_match('/^[a-z0-9]{24}$/', $suffix)) {
            $suffix = strtolower(wp_generate_password(24, false));
            update_option(self::OPTION, $suffix, false);
        }

        return rtrim(wp_normalize_path(WP_CONTENT_DIR), '/').'/wp-caregrid-quarantine-'.$suffix;
    }

    /** @throws QuarantineFailure */
    private static function ensure(): string
    {
        $dir = self::path();
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            throw new QuarantineFailure('QUARANTINE_UNAVAILABLE', 'The quarantine directory cannot be created.');
        }

        @chmod($dir, 0700);
        @file_put_contents($dir.'/index.php', "<?php\n// Silence.\n");
        @file_put_contents($dir.'/.htaccess', "Require all denied\nDeny from all\n<FilesMatch \".*\">\nSetHandler none\n</FilesMatch>\nphp_flag engine off\n");
        @file_put_contents($dir.'/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization><handlers><clear /></handlers></system.webServer></configuration>');

        if (! is_writable($dir)) {
            throw new QuarantineFailure('QUARANTINE_UNAVAILABLE', 'The quarantine directory is not writable.');
        }

        return $dir;
    }

    /**
     * Move a file into quarantine.
     *
     * @return array{original_path: string, sha256: string, size: int, modified_at: string, permissions: string}
     *
     * @throws QuarantineFailure
     */
    public static function quarantine(string $relative, string $absolute, string $id, array $meta): array
    {
        if (! preg_match('/^[0-9a-f-]{36}$/', $id)) {
            throw new QuarantineFailure('INVALID_PAYLOAD', 'Invalid quarantine reference.');
        }

        $dir = self::ensure();
        clearstatcache(true, $absolute);
        $record = [
            'original_path' => $relative,
            'sha256' => (string) hash_file('sha256', $absolute),
            'size' => (int) filesize($absolute),
            'modified_at' => gmdate('c', (int) filemtime($absolute)),
            'permissions' => substr(sprintf('%o', (int) fileperms($absolute)), -4),
        ];

        $target = $dir.'/'.$id.'.quarantined';
        if (file_exists($target) || ! @rename($absolute, $target)) {
            throw new QuarantineFailure('QUARANTINE_FAILED', 'The file could not be moved into quarantine.');
        }
        @chmod($target, 0600);

        file_put_contents($dir.'/'.$id.'.json', wp_json_encode($record + $meta + ['status' => 'quarantined', 'quarantined_at' => gmdate('c')]));
        @chmod($dir.'/'.$id.'.json', 0600);

        return $record;
    }

    /**
     * Put the quarantined copy of this path (with this hash) back.
     *
     * @return array{path: string, sha256: string}
     *
     * @throws QuarantineFailure
     */
    public static function restore(string $relative, string $sha256): array
    {
        $absolute = SitePaths::absolute($relative);
        if ($absolute === null) {
            throw new QuarantineFailure('UNSAFE_PATH', 'An unsafe path was refused.');
        }

        [$id, $record] = self::find($relative, $sha256) ?? throw new QuarantineFailure('QUARANTINE_NOT_FOUND', 'No quarantined copy of this file was found.');

        if (file_exists($absolute) || is_link($absolute)) {
            throw new QuarantineFailure('QUARANTINE_TARGET_EXISTS', 'A file exists at the original location; it is not overwritten.');
        }

        $stored = self::path().'/'.$id.'.quarantined';
        if (! hash_equals($sha256, (string) hash_file('sha256', $stored))) {
            throw new QuarantineFailure('QUARANTINE_CHANGED', 'The quarantined copy no longer matches its checksum.');
        }

        if (! is_dir(dirname($absolute)) && ! wp_mkdir_p(dirname($absolute))) {
            throw new QuarantineFailure('RESTORE_FAILED', 'The original directory cannot be recreated.');
        }

        if (! @rename($stored, $absolute)) {
            throw new QuarantineFailure('RESTORE_FAILED', 'The file could not be moved back.');
        }

        @chmod($absolute, octdec($record['permissions'] ?? '0644'));
        @touch($absolute, (int) strtotime((string) ($record['modified_at'] ?? 'now')));
        file_put_contents(self::path().'/'.$id.'.json', wp_json_encode(['status' => 'restored', 'restored_at' => gmdate('c')] + $record));

        return ['path' => $relative, 'sha256' => $sha256];
    }

    /** @return array{0: string, 1: array}|null */
    private static function find(string $relative, string $sha256): ?array
    {
        foreach (glob(self::path().'/*.json') ?: [] as $file) {
            $record = json_decode((string) file_get_contents($file), true);
            if (is_array($record) && ($record['status'] ?? null) === 'quarantined' && ($record['original_path'] ?? null) === $relative && hash_equals((string) ($record['sha256'] ?? ''), $sha256)) {
                return [basename($file, '.json'), $record];
            }
        }

        return null;
    }

    /** Files that must never be quarantined: the site's configuration and the connector itself. */
    public static function protected_path(string $relative): bool
    {
        $relative = PathGuard::relative($relative);

        return $relative === 'wp-config.php'
            || str_starts_with($relative, 'wp-content/plugins/wp-caregrid-connector/')
            || str_starts_with($relative, 'wp-content/wp-caregrid-');
    }
}
